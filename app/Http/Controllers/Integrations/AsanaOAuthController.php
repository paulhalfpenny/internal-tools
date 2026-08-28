<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Jobs\Asana\PullAsanaProjectsJob;
use App\Models\AsanaSyncLog;
use App\Models\AsanaWorkspace;
use App\Models\User;
use App\Services\Asana\AsanaHoursSyncRecovery;
use App\Services\Asana\AsanaService;
use App\Services\Asana\AsanaSyncActorAlert;
use App\Services\Asana\AsanaTokenManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AsanaOAuthController extends Controller
{
    private const AUTHORIZE_URL = 'https://app.asana.com/-/oauth_authorize';

    private const TOKEN_URL = 'https://app.asana.com/-/oauth_token';

    public function __construct(
        private readonly AsanaService $service,
        private readonly AsanaTokenManager $tokens,
        private readonly AsanaHoursSyncRecovery $hoursRecovery,
        private readonly AsanaSyncActorAlert $actorAlert,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $clientId = (string) config('services.asana.client_id');
        $redirect = (string) config('services.asana.redirect');

        if ($clientId === '' || $redirect === '') {
            return back()->with('asana_error', 'Asana integration is not configured.');
        }

        $state = Str::random(40);
        $request->session()->put('asana_oauth_state', $state);

        $url = self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'state' => $state,
            'scope' => 'default',
        ]);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('profile.asana')
                ->with('asana_error', 'Asana authorisation was cancelled.');
        }

        $expectedState = $request->session()->pull('asana_oauth_state');
        if ($expectedState === null || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return redirect()->route('profile.asana')
                ->with('asana_error', 'Asana authorisation state mismatch. Please try again.');
        }

        $code = (string) $request->query('code');
        if ($code === '') {
            return redirect()->route('profile.asana')
                ->with('asana_error', 'Asana did not return an authorisation code.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'client_id' => config('services.asana.client_id'),
            'client_secret' => config('services.asana.client_secret'),
            'redirect_uri' => config('services.asana.redirect'),
            'code' => $code,
        ]);

        if (! $response->successful()) {
            AsanaSyncLog::error('asana.oauth.token_exchange_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return redirect()->route('profile.asana')
                ->with('asana_error', 'Asana rejected the authorisation. Try connecting again.');
        }

        $data = $response->json();
        $accessToken = $data['access_token'] ?? null;
        $refreshToken = $data['refresh_token'] ?? null;

        if ($accessToken === null) {
            return redirect()->route('profile.asana')
                ->with('asana_error', 'Asana did not return an access token.');
        }

        /** @var User $user */
        $user = $request->user();

        // Asana omits refresh_token when re-authorising an already-authorised app.
        // Overwriting with null there strands the connection: the access token expires
        // an hour later with nothing to refresh it. Keep whatever we already hold.
        //
        // Filled in memory only — getMe() below reads the token straight off the model,
        // so nothing is persisted until we have the full picture. A request that dies
        // mid-flow then leaves the stored connection untouched rather than half-written.
        $user->forceFill([
            'asana_access_token' => $accessToken,
            'asana_refresh_token' => $refreshToken ?? $user->asana_refresh_token,
            'asana_token_expires_at' => now()->addSeconds(max(0, ($data['expires_in'] ?? 3600) - 60)),
        ]);

        try {
            $me = $this->service->forUser($user)->getMe();
        } catch (\Throwable $e) {
            $this->tokens->disconnect($user);
            AsanaSyncLog::error('asana.oauth.fetch_me_failed', ['error' => $e->getMessage()], $user);

            return redirect()->route('profile.asana')
                ->with('asana_error', 'Connected to Asana but could not fetch your profile. Please try again.');
        }

        $workspaces = $me['workspaces'];
        $defaultWorkspace = $workspaces[0] ?? null;

        $user->forceFill([
            'asana_user_gid' => $me['gid'],
            'asana_workspace_gid' => $defaultWorkspace['gid'] ?? null,
            // A successful reconnect clears the drop, so the health check stops chasing it.
            'asana_connection_lost_at' => null,
            'asana_connection_alerted_at' => null,
        ])->save();

        foreach ($workspaces as $workspace) {
            AsanaWorkspace::updateOrCreate(
                ['gid' => $workspace['gid']],
                ['name' => $workspace['name'], 'last_synced_at' => now()],
            );
        }

        if ($defaultWorkspace !== null) {
            PullAsanaProjectsJob::dispatch($defaultWorkspace['gid'], $user->id);
        }

        AsanaSyncLog::info('asana.oauth.connected', [
            'user_id' => $user->id,
            'asana_user_gid' => $me['gid'],
            'workspace_count' => count($workspaces),
        ], $user);

        if (User::asanaSyncActor()?->is($user)) {
            $this->actorAlert->resolve();
            $queued = $this->hoursRecovery->dispatchPending();
            AsanaSyncLog::info('asana.sync_hours.recovery_queued', [
                'actor_user_id' => $user->id,
                'actor_asana_user_gid' => $user->asana_user_gid,
                'totals_queued' => $queued,
            ], $user);
        }

        return redirect()->route('profile.asana')
            ->with('asana_status', 'Connected to Asana as '.$me['name'].'.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $wasSyncActor = User::asanaSyncActor()?->is($user) ?? false;
        $this->tokens->disconnect($user);

        AsanaSyncLog::info('asana.oauth.disconnected', ['user_id' => $user->id], $user);
        if ($wasSyncActor) {
            $this->actorAlert->reportUnavailable($user, 'actor_disconnected');
        }

        return redirect()->route('profile.asana')->with('asana_status', 'Disconnected from Asana.');
    }
}

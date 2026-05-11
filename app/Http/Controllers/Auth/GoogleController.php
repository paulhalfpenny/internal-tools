<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

class GoogleController extends Controller
{
    public function redirect(): RedirectResponse
    {
        /** @var GoogleProvider $provider */
        $provider = Socialite::driver('google');

        return $provider
            ->scopes(['openid', 'email', 'profile', 'https://www.googleapis.com/auth/calendar.readonly'])
            ->with(['access_type' => 'offline', 'prompt' => 'select_account'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        /** @var SocialiteUser $googleUser */
        $googleUser = Socialite::driver('google')->user();

        // Server-side domain restriction — do not trust the client
        $hd = $googleUser->user['hd'] ?? null;
        $email = $googleUser->getEmail() ?? '';
        $emailVerified = $googleUser->user['email_verified'] ?? false;

        if (
            $hd !== 'filteragency.com' ||
            ! str_ends_with($email, '@filteragency.com') ||
            ! $emailVerified
        ) {
            return redirect()->route('auth.error')
                ->with('error', 'Access is restricted to filteragency.com accounts.');
        }

        // Match on Google's stable subject ID first. Fall back to email only when no row
        // with that sub exists AND the matching row has never been bound to a Google
        // account (google_sub is null) — this prevents account takeover where someone
        // can claim an imported/seeded row by signing in with a colliding email.
        $user = User::where('google_sub', $googleUser->getId())->first();

        if ($user === null) {
            $emailMatch = User::where('email', $email)->first();
            if ($emailMatch !== null && $emailMatch->google_sub !== null) {
                return redirect()->route('auth.error')
                    ->with('error', 'This email is already linked to a different Google account. Contact an administrator.');
            }
            $user = $emailMatch;
        }

        // Socialite's User docblock declares these as non-null, but Google omits
        // refresh_token on subsequent logins and may omit expires_in too, so the
        // defensiveness is intentional. Local nullable copies tell PHPStan.
        /** @var string|null $newRefreshToken */
        $newRefreshToken = $googleUser->refreshToken;
        /** @var int|null $expiresIn */
        $expiresIn = $googleUser->expiresIn;

        if ($user === null) {
            $user = User::create([
                'google_sub' => $googleUser->getId(),
                'email' => $email,
                'name' => $googleUser->getName(),
                'role' => Role::User,
                'is_active' => true,
                'google_access_token' => $googleUser->token,
                'google_refresh_token' => $newRefreshToken,
                'google_token_expires_at' => now()->addSeconds(max(0, ($expiresIn ?? 3600) - 60)),
            ]);
        } else {
            $user->update([
                'google_sub' => $googleUser->getId(),
                'name' => $googleUser->getName(),
                'last_login_at' => now(),
                'google_access_token' => $googleUser->token,
                'google_refresh_token' => $newRefreshToken ?? $user->google_refresh_token,
                'google_token_expires_at' => now()->addSeconds(max(0, ($expiresIn ?? 3600) - 60)),
            ]);
        }

        if (! $user->is_active) {
            return redirect()->route('auth.error')
                ->with('error', 'Your account has been deactivated. Contact an administrator.');
        }

        Auth::login($user, remember: true);

        // Rotate the session ID so the pre-auth session can't be replayed.
        $request->session()->regenerate();

        return redirect()->intended(route('timesheet'));
    }
}

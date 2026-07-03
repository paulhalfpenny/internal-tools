<?php

namespace App\Http\Controllers\Integrations;

use App\Domain\TimeTracking\AsanaAppService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Endpoints called by Asana's app-components platform (signed requests, no
 * web session — see VerifyAsanaAppSignature). The exception is show(), the
 * human-facing attachment URL, which lives behind the normal auth stack.
 */
class AsanaAppController extends Controller
{
    public function __construct(private readonly AsanaAppService $service) {}

    public function form(Request $request): JsonResponse
    {
        $user = $this->service->resolveUser($request->query('user'));
        if ($user === null) {
            return response()->json($this->service->connectPromptForm());
        }

        return response()->json(
            $this->service->formMetadata($user, (string) $request->query('task', ''))
        );
    }

    public function change(Request $request): JsonResponse
    {
        $data = $this->data($request);

        $user = $this->service->resolveUser($data['user'] ?? null);
        if ($user === null) {
            return response()->json($this->service->connectPromptForm());
        }

        return response()->json($this->service->formMetadata(
            $user,
            (string) ($data['task'] ?? ''),
            is_array($data['values'] ?? null) ? $data['values'] : [],
        ));
    }

    /**
     * Asana's on_submit contract: 200 responses must attach a resource (the
     * widget card), anything else renders a generic error client-side.
     * Validation failures return 400 + the re-rendered form, the documented
     * error shape.
     */
    public function submit(Request $request): JsonResponse
    {
        $data = $this->data($request);

        $user = $this->service->resolveUser($data['user'] ?? null);
        if ($user === null) {
            return response()->json($this->service->connectPromptForm(), 400);
        }

        $body = $this->service->submit(
            $user,
            (string) ($data['task'] ?? ''),
            is_array($data['values'] ?? null) ? $data['values'] : [],
        );

        return response()->json($body, isset($body['resource_url']) ? 200 : 400);
    }

    public function widget(Request $request): JsonResponse
    {
        $user = $this->service->resolveUser($request->query('user'));

        // The attached resource URL ends in the task gid.
        $resourceUrl = (string) $request->query('resource_url', '');
        $taskGid = (string) ($request->query('task', ''));
        if (preg_match('#/asana-app/tasks/([0-9A-Za-z]+)#', $resourceUrl, $m) === 1) {
            $taskGid = $m[1];
        }

        return response()->json($this->service->widget($user, $taskGid));
    }

    /**
     * The widget card's link. Once the card occupies the app's slot on a
     * task, the in-Asana form is unreachable, so this deep-links into the
     * timesheet with the entry modal opened and prefilled for the task.
     */
    public function show(string $taskGid): RedirectResponse
    {
        return redirect()->route('timesheet', ['log_asana' => $taskGid]);
    }

    /**
     * The verified JSON blob from the POST body's "data" field.
     *
     * @return array<string, mixed>
     */
    private function data(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);
        $blob = is_array($decoded) ? ($decoded['data'] ?? null) : null;
        $data = is_string($blob) ? json_decode($blob, true) : null;

        return is_array($data) ? $data : [];
    }
}

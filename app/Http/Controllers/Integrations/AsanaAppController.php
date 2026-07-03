<?php

namespace App\Http\Controllers\Integrations;

use App\Domain\TimeTracking\AsanaAppService;
use App\Http\Controllers\Controller;
use App\Models\AsanaTask;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
     * receipt), anything else renders a generic error client-side. Validation
     * failures return 400 + the re-rendered form, the documented error shape.
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
     * Human-facing fallback for the attached resource link: send the user to
     * the mapped project's budget page, else their timesheet.
     */
    public function show(string $taskGid): RedirectResponse
    {
        $boardGid = AsanaTask::find($taskGid)?->asana_project_gid;

        // Budget pages sit behind the manager-only reports gate; everyone
        // else lands on their own timesheet.
        if ($boardGid !== null && Gate::allows('access-reports')) {
            $project = Project::where('is_archived', false)
                ->whereHas('asanaProjects', fn ($q) => $q->where('gid', $boardGid))
                ->orderBy('name')
                ->first();

            if ($project !== null) {
                return redirect()->route('reports.projects.budget', $project);
            }
        }

        return redirect()->route('timesheet');
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

<?php

namespace App\Services\Asana;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AsanaService
{
    private const BASE_URL = 'https://app.asana.com/api/1.0';

    private ?User $user = null;

    public function __construct(private readonly AsanaTokenManager $tokens) {}

    public function forUser(User $user): self
    {
        $clone = new self($this->tokens);
        $clone->user = $user;

        return $clone;
    }

    /**
     * @return array{gid: string, name: string, email: string|null, workspaces: list<array{gid: string, name: string}>}
     */
    public function getMe(): array
    {
        $payload = $this->client()
            ->get(self::BASE_URL.'/users/me', ['opt_fields' => 'gid,name,email,workspaces.gid,workspaces.name'])
            ->throw()
            ->json('data', []);

        return [
            'gid' => $payload['gid'],
            'name' => $payload['name'] ?? '',
            'email' => $payload['email'] ?? null,
            'workspaces' => array_map(
                fn (array $w) => ['gid' => $w['gid'], 'name' => $w['name']],
                $payload['workspaces'] ?? []
            ),
        ];
    }

    /**
     * @return list<array{gid: string, name: string, archived: bool}>
     */
    public function getProjects(string $workspaceGid): array
    {
        return $this->paginated('/projects', [
            'workspace' => $workspaceGid,
            'archived' => 'false',
            'opt_fields' => 'gid,name,archived',
        ], fn (array $p) => [
            'gid' => $p['gid'],
            'name' => $p['name'],
            'archived' => $p['archived'] ?? false,
        ]);
    }

    /**
     * @return list<array{gid: string, name: string, completed: bool, parent_gid: string|null}>
     */
    public function getTasks(string $projectGid): array
    {
        return $this->paginated('/projects/'.$projectGid.'/tasks', [
            'opt_fields' => 'gid,name,completed,parent.gid',
            'completed_since' => 'now',
        ], fn (array $t) => [
            'gid' => $t['gid'],
            'name' => $t['name'],
            'completed' => $t['completed'] ?? false,
            'parent_gid' => $t['parent']['gid'] ?? null,
        ]);
    }

    /**
     * Find or create the cumulative-hours custom field on the given Asana
     * project. Returns the field gid. Searches three places in order:
     *   1. Custom fields already attached to this project.
     *   2. Custom fields in the workspace (may exist but not attached here).
     *   3. Otherwise create a new workspace-level field.
     * In cases 2 and 3 the field is attached to the project before returning.
     *
     * @throws RequestException
     */
    public function ensureHoursCustomField(string $projectGid, string $workspaceGid): string
    {
        $fieldName = (string) config('services.asana.custom_field_name');

        $projectSettings = $this->client()
            ->get(self::BASE_URL.'/projects/'.$projectGid.'/custom_field_settings', [
                'opt_fields' => 'custom_field.gid,custom_field.name',
                'limit' => 100,
            ])
            ->throw()
            ->json('data', []);

        foreach ($projectSettings as $setting) {
            $field = $setting['custom_field'] ?? null;
            if ($field !== null && ($field['name'] ?? null) === $fieldName) {
                return $field['gid'];
            }
        }

        $workspaceFields = $this->paginated(
            '/workspaces/'.$workspaceGid.'/custom_fields',
            ['opt_fields' => 'gid,name'],
            fn (array $f) => ['gid' => $f['gid'] ?? null, 'name' => $f['name'] ?? null],
        );

        $existingGid = null;
        foreach ($workspaceFields as $field) {
            if ($field['name'] === $fieldName) {
                $existingGid = $field['gid'];
                break;
            }
        }

        if ($existingGid === null) {
            $created = $this->client()
                ->post(self::BASE_URL.'/custom_fields', [
                    'data' => [
                        'workspace' => $workspaceGid,
                        'name' => $fieldName,
                        'resource_subtype' => 'number',
                        'precision' => 2,
                        'description' => 'Cumulative hours tracked in Internal Tools across all users.',
                    ],
                ])
                ->throw()
                ->json('data', []);

            $existingGid = $created['gid'] ?? null;
            if ($existingGid === null) {
                throw new RuntimeException('Asana did not return a gid when creating the custom field.');
            }
        }

        $this->client()
            ->post(self::BASE_URL.'/projects/'.$projectGid.'/addCustomFieldSetting', [
                'data' => [
                    'custom_field' => $existingGid,
                    'is_important' => true,
                ],
            ])
            ->throw();

        return $existingGid;
    }

    /**
     * Set the numeric value of a custom field on a task.
     *
     * @throws RequestException
     */
    public function setTaskHours(string $taskGid, string $customFieldGid, float $hours): void
    {
        $this->client()
            ->put(self::BASE_URL.'/tasks/'.$taskGid, [
                'data' => [
                    'custom_fields' => [
                        $customFieldGid => round($hours, 2),
                    ],
                ],
            ])
            ->throw();
    }

    /**
     * @template T
     *
     * @param  array<string, scalar>  $query
     * @param  callable(array<string, mixed>): T  $map
     * @return list<T>
     */
    private function paginated(string $path, array $query, callable $map): array
    {
        $items = [];
        $offset = null;
        $limit = 100;

        do {
            $params = array_merge($query, ['limit' => $limit]);
            if ($offset !== null) {
                $params['offset'] = $offset;
            }

            $response = $this->client()
                ->get(self::BASE_URL.$path, $params)
                ->throw();

            foreach ($response->json('data', []) as $item) {
                $items[] = $map($item);
            }

            $offset = $response->json('next_page.offset');
        } while ($offset !== null);

        return $items;
    }

    private function client(): PendingRequest
    {
        if ($this->user === null) {
            throw new RuntimeException('AsanaService used without calling forUser() first.');
        }

        $token = $this->tokens->getValidToken($this->user);
        if ($token === null) {
            throw new RuntimeException('User is not connected to Asana.');
        }

        return Http::withToken($token)
            ->acceptJson()
            ->retry(2, 200, throw: false);
    }
}

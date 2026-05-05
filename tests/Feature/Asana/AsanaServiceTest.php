<?php

use App\Models\User;
use App\Services\Asana\AsanaService;
use App\Services\Asana\AsanaTokenManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function asanaTestServiceWithUser(): array
{
    config([
        'services.asana.client_id' => 'test-client',
        'services.asana.client_secret' => 'test-secret',
        'services.asana.redirect' => 'http://localhost/cb',
        'services.asana.custom_field_name' => 'Hours tracked (Internal Tools)',
    ]);

    $user = User::factory()->create([
        'asana_access_token' => 'tok',
        'asana_token_expires_at' => now()->addHour(),
        'asana_user_gid' => 'me',
        'asana_workspace_gid' => 'ws-1',
    ]);
    $service = (new AsanaService(new AsanaTokenManager))->forUser($user);

    return [$user, $service];
}

test('getMe returns normalized user data', function () {
    [, $service] = asanaTestServiceWithUser();
    Http::fake([
        'app.asana.com/api/1.0/users/me*' => Http::response([
            'data' => [
                'gid' => 'me',
                'name' => 'Pat',
                'email' => 'pat@x.test',
                'workspaces' => [['gid' => 'ws-1', 'name' => 'Acme']],
            ],
        ]),
    ]);

    $me = $service->getMe();
    expect($me['gid'])->toBe('me');
    expect($me['workspaces'])->toHaveCount(1);
    expect($me['workspaces'][0]['name'])->toBe('Acme');
});

test('getProjects paginates and filters archived', function () {
    [, $service] = asanaTestServiceWithUser();

    Http::fake([
        'app.asana.com/api/1.0/projects*' => Http::sequence()
            ->push([
                'data' => [
                    ['gid' => 'p1', 'name' => 'One', 'archived' => false],
                    ['gid' => 'p2', 'name' => 'Two', 'archived' => false],
                ],
                'next_page' => ['offset' => 'cur-2'],
            ])
            ->push([
                'data' => [
                    ['gid' => 'p3', 'name' => 'Three', 'archived' => false],
                ],
                'next_page' => null,
            ]),
    ]);

    $projects = $service->getProjects('ws-1');
    expect($projects)->toHaveCount(3);
    expect($projects[2]['name'])->toBe('Three');
});

test('ensureHoursCustomField returns existing field when name matches', function () {
    [, $service] = asanaTestServiceWithUser();

    Http::fake([
        'app.asana.com/api/1.0/projects/p1/custom_field_settings*' => Http::response([
            'data' => [
                ['custom_field' => ['gid' => 'f-other', 'name' => 'Priority']],
                ['custom_field' => ['gid' => 'f-hours', 'name' => 'Hours tracked (Internal Tools)']],
            ],
        ]),
    ]);

    $gid = $service->ensureHoursCustomField('p1', 'ws-1');
    expect($gid)->toBe('f-hours');
});

test('ensureHoursCustomField creates and adds field when missing everywhere', function () {
    [, $service] = asanaTestServiceWithUser();

    Http::fake([
        'app.asana.com/api/1.0/projects/p1/custom_field_settings*' => Http::response(['data' => []]),
        'app.asana.com/api/1.0/workspaces/ws-1/custom_fields*' => Http::response(['data' => []]),
        'app.asana.com/api/1.0/custom_fields' => Http::response(['data' => ['gid' => 'new-field']]),
        'app.asana.com/api/1.0/projects/p1/addCustomFieldSetting' => Http::response(['data' => ['gid' => 'setting-1']]),
    ]);

    $gid = $service->ensureHoursCustomField('p1', 'ws-1');
    expect($gid)->toBe('new-field');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/custom_fields') && $r->method() === 'POST');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/addCustomFieldSetting'));
});

test('ensureHoursCustomField attaches existing workspace field instead of creating a duplicate', function () {
    [, $service] = asanaTestServiceWithUser();

    Http::fake([
        'app.asana.com/api/1.0/projects/p1/custom_field_settings*' => Http::response(['data' => []]),
        'app.asana.com/api/1.0/workspaces/ws-1/custom_fields*' => Http::response([
            'data' => [
                ['gid' => 'f-other', 'name' => 'Priority'],
                ['gid' => 'f-existing', 'name' => 'Hours tracked (Internal Tools)'],
            ],
        ]),
        'app.asana.com/api/1.0/projects/p1/addCustomFieldSetting' => Http::response(['data' => ['gid' => 'setting-1']]),
    ]);

    $gid = $service->ensureHoursCustomField('p1', 'ws-1');
    expect($gid)->toBe('f-existing');

    Http::assertNotSent(fn ($r) => $r->url() === 'https://app.asana.com/api/1.0/custom_fields' && $r->method() === 'POST');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/addCustomFieldSetting'));
});

test('setTaskHours PUTs the rounded value to the task', function () {
    [, $service] = asanaTestServiceWithUser();
    Http::fake(['app.asana.com/api/1.0/tasks/T1' => Http::response(['data' => []])]);

    $service->setTaskHours('T1', 'F1', 3.456);

    Http::assertSent(function ($r) {
        return $r->method() === 'PUT'
            && str_contains($r->url(), '/tasks/T1')
            && $r['data']['custom_fields']['F1'] === 3.46;
    });
});

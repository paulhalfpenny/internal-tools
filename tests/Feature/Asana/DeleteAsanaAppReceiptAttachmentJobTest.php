<?php

use App\Jobs\Asana\DeleteAsanaAppReceiptAttachmentJob;
use App\Models\User;
use App\Services\Asana\AsanaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('deletes only the receipt attachment for the task', function () {
    $user = User::factory()->create([
        'asana_user_gid' => 'AU1',
        'asana_access_token' => 'token-1',
        'asana_token_expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'app.asana.com/api/1.0/tasks/T1/attachments*' => Http::response(['data' => [
            ['gid' => 'A1', 'view_url' => 'https://example.com/some-doc.pdf'],
            ['gid' => 'A2', 'view_url' => 'https://internal.filter.agency/timesheet?from_asana=T1'],
            ['gid' => 'A3', 'view_url' => null],
        ]]),
        'app.asana.com/api/1.0/attachments/A2' => Http::response(['data' => []]),
    ]);

    (new DeleteAsanaAppReceiptAttachmentJob($user->id, 'T1'))->handle(app(AsanaService::class));

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/attachments/A2'));
    Http::assertNotSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/attachments/A1'));
});

test('quietly does nothing when the user no longer exists', function () {
    Http::fake();

    (new DeleteAsanaAppReceiptAttachmentJob(999999, 'T1'))->handle(app(AsanaService::class));

    Http::assertNothingSent();
});

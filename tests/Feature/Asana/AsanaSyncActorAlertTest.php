<?php

use App\Models\User;
use App\Notifications\AsanaSyncActorUnavailable;
use App\Services\Asana\AsanaSyncActorAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.asana.sync_alert_email' => 'paul@filteragency.com']);
    app(AsanaSyncActorAlert::class)->resolve();
});

test('emails Paul once per outage and allows a new alert after recovery', function () {
    Notification::fake();
    $actor = User::factory()->create();
    $alerts = app(AsanaSyncActorAlert::class);

    expect($alerts->reportUnavailable($actor, 'actor_no_token'))->toBeTrue()
        ->and($alerts->reportUnavailable($actor, 'actor_no_token'))->toBeFalse();

    Notification::assertSentOnDemandTimes(
        'App\\Notifications\\AsanaSyncActorUnavailable',
        1,
    );

    $alerts->resolve();
    expect($alerts->reportUnavailable($actor, 'actor_no_token'))->toBeTrue();

    Notification::assertSentOnDemandTimes(
        'App\\Notifications\\AsanaSyncActorUnavailable',
        2,
    );
});

test('renders an email explaining that hours remain pending', function () {
    $notification = new AsanaSyncActorUnavailable(
        actorName: 'Internal Tools',
        actorEmail: 'internal-tools@example.com',
        reason: 'actor_no_token',
    );

    $html = (string) $notification->toMail(new AnonymousNotifiable)->render();

    expect($html)->toContain('Internal Tools Asana sync is paused')
        ->and($html)->toContain('internal-tools@example.com')
        ->and($html)->toContain('will be retried');
});

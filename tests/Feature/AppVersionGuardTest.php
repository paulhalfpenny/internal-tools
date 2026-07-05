<?php

use App\Support\AppVersion;

test('web responses carry the current X-App-Version header', function () {
    $this->get(route('auth.login'))
        ->assertOk()
        ->assertHeader('X-App-Version', AppVersion::current());
});

test('AppVersion::current is stable within a request', function () {
    expect(AppVersion::current())->toBe(AppVersion::current());
});

<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\HtmlString;

uses(RefreshDatabase::class);

test('layouts preload the published versioned Livewire runtime', function () {
    config()->set('app.debug', false);

    $manifest = public_path('vendor/livewire/manifest.json');
    $manifestExists = File::exists($manifest);
    $originalManifest = $manifestExists ? File::get($manifest) : null;

    File::ensureDirectoryExists(dirname($manifest));
    File::put($manifest, json_encode(['/livewire.js' => 'runtime-version'], JSON_THROW_ON_ERROR));

    try {
        $this->actingAs(User::factory()->create());

        foreach (['app', 'embed'] as $layout) {
            $html = view("layouts.{$layout}", ['slot' => new HtmlString('Runtime test')])->render();

            expect($html)
                ->toContain('<link rel="preload" href="/vendor/livewire/livewire.min.js?id=runtime-version" as="script">');
        }
    } finally {
        if ($manifestExists) {
            File::put($manifest, $originalManifest);
        } else {
            File::delete($manifest);
            File::deleteDirectory(dirname($manifest));
        }
    }
});

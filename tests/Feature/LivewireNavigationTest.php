<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('every visible primary navigation link uses Livewire hover navigation', function () {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('timesheet'))
        ->assertOk();

    $page = $response->getContent();
    $navigationStart = strpos($page, '<nav');
    $navigationEnd = strpos($page, '</nav>', $navigationStart);

    expect($navigationStart)->not->toBeFalse()
        ->and($navigationEnd)->not->toBeFalse();

    $navigation = substr($page, $navigationStart, $navigationEnd - $navigationStart + strlen('</nav>'));
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML($navigation);
    libxml_clear_errors();

    foreach ($document->getElementsByTagName('nav')->item(0)->getElementsByTagName('a') as $link) {
        expect($link->getAttribute('href'))->not->toBe('')
            ->and($link->hasAttribute('wire:navigate.hover'))->toBeTrue();
    }
});

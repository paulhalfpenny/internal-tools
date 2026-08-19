<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('a stuck-open picker diagnostic is retained at production log level', function () {
    Log::spy();

    $this->actingAs(User::factory()->create())
        ->postJson(route('diagnostics.picker'), [
            'kind' => 'task-picker-stuck-open',
            'clickedId' => '3',
            'committed' => '3',
            'isOpen' => true,
        ])
        ->assertNoContent();

    Log::shouldHaveReceived('error')
        ->once()
        ->with('picker.diagnostic', Mockery::on(fn (array $context): bool => $context['kind'] === 'task-picker-stuck-open'));
});

<?php

namespace App\Support;

final class LivewireRuntimeAsset
{
    public function __construct(private readonly ?string $publicPath = null) {}

    public function preloadUrl(): ?string
    {
        $manifestPath = rtrim($this->publicPath ?? public_path(), '/').'/vendor/livewire/manifest.json';

        if (! is_file($manifestPath)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $version = is_array($manifest) ? ($manifest['/livewire.js'] ?? null) : null;

        if (! is_string($version) || $version === '') {
            return null;
        }

        $file = config('app.debug')
            ? (config('livewire.csp_safe') ? 'livewire.csp.js' : 'livewire.js')
            : (config('livewire.csp_safe') ? 'livewire.csp.min.js' : 'livewire.min.js');

        return "/vendor/livewire/{$file}?id={$version}";
    }
}

<?php

$csv = static fn (string $value): array => array_values(array_filter(
    array_map('trim', explode(',', $value)),
    static fn (string $item): bool => $item !== '',
));

return [
    'redirect_domains' => $csv(env('MCP_REDIRECT_DOMAINS', 'http://localhost,http://127.0.0.1')),

    'custom_schemes' => $csv(env('MCP_CUSTOM_SCHEMES', 'claude,cursor,vscode')),

    'authorization_server' => env('MCP_AUTHORIZATION_SERVER'),

    'pending_action_ttl_minutes' => (int) env('MCP_PENDING_ACTION_TTL_MINUTES', 60),
];

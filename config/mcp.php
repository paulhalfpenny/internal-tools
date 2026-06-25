<?php

$csv = static fn (string $value): array => array_values(array_filter(
    array_map('trim', explode(',', $value)),
    static fn (string $item): bool => $item !== '',
));

return [
    'redirect_domains' => $csv(env('MCP_REDIRECT_DOMAINS', 'http://localhost,http://127.0.0.1,https://claude.ai,https://chatgpt.com')),

    'custom_schemes' => $csv(env('MCP_CUSTOM_SCHEMES', 'claude,cursor,vscode')),

    'authorization_server' => env('MCP_AUTHORIZATION_SERVER'),

    'oauth_registration_limit_per_minute' => (int) env('MCP_OAUTH_REGISTRATION_LIMIT_PER_MINUTE', 10),

    'pending_action_ttl_minutes' => (int) env('MCP_PENDING_ACTION_TTL_MINUTES', 60),
];

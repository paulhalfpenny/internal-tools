<?php

// The Asana app-components form/widget requests originate from the user's
// browser on app.asana.com, so those endpoints need CORS headers. Everything
// else in the app is same-origin and stays un-CORS'd.
return [
    'paths' => ['asana-app/*'],
    'allowed_methods' => ['GET', 'POST'],
    'allowed_origins' => ['https://app.asana.com'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];

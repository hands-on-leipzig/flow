<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:5173',
        'http://localhost:5174',
        'http://localhost:5175',
        'https://flow.hands-on-technology.org',
        'https://test.flow.hands-on-technology.org',
        'https://dev.flow.hands-on-technology.org',
        'https://hero.hands-on-technology.org',
        'https://test.hero.hands-on-technology.org',
        'https://join.hands-on-technology.org',
        'https://test.node.hands-on-technology.org',
        'https://handson.tools',
    ],
    // Public HERO/JOIN callers (Vite --host, 127.0.0.1, LAN, preview URLs).
    // FLOW's own SPA is same-origin (or Vite-proxied); auth is Bearer, not cookies.
    'allowed_origins_patterns' => [
        '#^https?://#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];

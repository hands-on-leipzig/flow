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
        // JOIN reads the public event links from here; production and test deployment.
        'https://join.hands-on-technology.org',
        'https://test.node.hands-on-technology.org',
        'https://handson.tools',
    ],
    // Local Vite often uses 127.0.0.1 or another port; HERO/JOIN test hosts vary.
    'allowed_origins_patterns' => [
        '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#',
        '#^https://([a-z0-9-]+\.)?(hero|join|node)\.hands-on-technology\.org$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];

<?php

declare(strict_types=1);

// config for NicolasKion/Esi
return [
    'user_agent' => env('APP_USER_AGENT') ?: (env('APP_NAME', 'Wormhole Systems').' | '.env('APP_URL', 'https://wormhole.systems').' | contact: '.(env('CONTACT_EMAIL') ?: 'admin@localhost')),
    'client_id' => env('EVE_CLIENT_ID'),
    'client_secret' => env('EVE_CLIENT_SECRET'),
    'retry_policy' => [
        'tries' => 5,
        'delay' => 5000,
    ],
];

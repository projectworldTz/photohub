<?php

return [
    'mode' => env('PHOTOHUB_MODE', 'cloud'),
    'cloud_enabled' => env('PHOTOHUB_CLOUD_ENABLED', false),
    'cloud_url' => env('PHOTOHUB_CLOUD_URL'),
    // Bind this installation's credential to ONE local business, never every tenant.
    'business_id' => (int) env('PHOTOHUB_LOCAL_BUSINESS_ID', 0),
    'studio_token' => env('PHOTOHUB_STUDIO_TOKEN'),
    'allow_http' => env('PHOTOHUB_ALLOW_HTTP', false),
    'auto_selections' => env('PHOTOHUB_AUTO_SELECTIONS', true),
];

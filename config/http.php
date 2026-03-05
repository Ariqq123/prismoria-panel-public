<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Rate Limits
    |--------------------------------------------------------------------------
    |
    | Defines the rate limit for the number of requests per minute that can be
    | executed against both the client and internal (application) APIs over the
    | defined period (by default, 1 minute).
    |
    */
    'rate_limit' => [
        'client_period' => 1,
        'client' => env('APP_API_CLIENT_RATELIMIT', 720),

        'application_period' => 1,
        'application' => env('APP_API_APPLICATION_RATELIMIT', 240),

        'external_panel_period' => 1,
        'external_panel' => env('APP_API_EXTERNAL_PANEL_RATELIMIT', 120),

        'external_panel_upload_period' => 1,
        'external_panel_upload' => env('APP_API_EXTERNAL_PANEL_UPLOAD_RATELIMIT', 900),
    ],
];

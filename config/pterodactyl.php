<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Restricted Environment
    |--------------------------------------------------------------------------
    |
    | Set this environment variable to true to enable a restricted configuration
    | setup on the panel. When set to true, configurations stored in the
    | database will not be applied.
    */

    'load_environment_only' => (bool) env('APP_ENVIRONMENT_ONLY', false),

    /*
    |--------------------------------------------------------------------------
    | Service Author
    |--------------------------------------------------------------------------
    |
    | Each panel installation is assigned a unique UUID to identify the
    | author of custom services, and make upgrades easier by identifying
    | standard Pterodactyl shipped services.
    */

    'service' => [
        'author' => env('APP_SERVICE_AUTHOR', 'unknown@unknown.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Should login success and failure events trigger an email to the user?
    */

    'auth' => [
        '2fa_required' => env('APP_2FA_REQUIRED', 0),
        '2fa' => [
            'bytes' => 32,
            'window' => env('APP_2FA_WINDOW', 4),
            'verify_newer' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Panel UI
    |--------------------------------------------------------------------------
    |
    | Configure client-facing panel interface options.
    */
    'ui' => [
        'background_image' => env('PTERODACTYL_UI_BACKGROUND_IMAGE', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Certain pagination result counts can be configured here and will take
    | effect globally.
    */

    'paginate' => [
        'frontend' => [
            'servers' => env('APP_PAGINATE_FRONT_SERVERS', 15),
        ],
        'admin' => [
            'servers' => env('APP_PAGINATE_ADMIN_SERVERS', 25),
            'users' => env('APP_PAGINATE_ADMIN_USERS', 25),
        ],
        'api' => [
            'nodes' => env('APP_PAGINATE_API_NODES', 25),
            'servers' => env('APP_PAGINATE_API_SERVERS', 25),
            'users' => env('APP_PAGINATE_API_USERS', 25),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Guzzle Connections
    |--------------------------------------------------------------------------
    |
    | Configure the timeout to be used for Guzzle connections here.
    */

    'guzzle' => [
        'timeout' => env('GUZZLE_TIMEOUT', 15),
        'connect_timeout' => env('GUZZLE_CONNECT_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | CDN
    |--------------------------------------------------------------------------
    |
    | Information for the panel to use when contacting the CDN to confirm
    | if panel is up to date.
    */

    'cdn' => [
        'cache_time' => 60,
        'url' => 'https://cdn.pterodactyl.io/releases/latest.json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Features
    |--------------------------------------------------------------------------
    |
    | Allow clients to create their own databases.
    */

    'client_features' => [
        'databases' => [
            'enabled' => env('PTERODACTYL_CLIENT_DATABASES_ENABLED', true),
            'allow_random' => env('PTERODACTYL_CLIENT_DATABASES_ALLOW_RANDOM', true),
        ],

        'schedules' => [
            // The total number of tasks that can exist for any given schedule at once.
            'per_schedule_task_limit' => env('PTERODACTYL_PER_SCHEDULE_TASK_LIMIT', 10),
        ],

        'allocations' => [
            'enabled' => env('PTERODACTYL_CLIENT_ALLOCATIONS_ENABLED', false),
            'range_start' => env('PTERODACTYL_CLIENT_ALLOCATIONS_RANGE_START'),
            'range_end' => env('PTERODACTYL_CLIENT_ALLOCATIONS_RANGE_END'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Editor
    |--------------------------------------------------------------------------
    |
    | This array includes the MIME filetypes that can be edited via the web.
    */

    'files' => [
        'max_edit_size' => env('PTERODACTYL_FILES_MAX_EDIT_SIZE', 1024 * 1024 * 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dynamic Environment Variables
    |--------------------------------------------------------------------------
    |
    | Place dynamic environment variables here that should be auto-appended
    | to server environment fields when the server is created or updated.
    |
    | Items should be in 'key' => 'value' format, where key is the environment
    | variable name, and value is the server-object key. For example:
    |
    | 'P_SERVER_CREATED_AT' => 'created_at'
    */

    'environment_variables' => [
        'P_SERVER_ALLOCATION_LIMIT' => 'allocation_limit',
    ],

    /*
    |--------------------------------------------------------------------------
    | Asset Verification
    |--------------------------------------------------------------------------
    |
    | This section controls the output format for JS & CSS assets.
    */

    'assets' => [
        'use_hash' => env('PTERODACTYL_USE_ASSET_HASH', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Notification Settings
    |--------------------------------------------------------------------------
    |
    | This section controls what notifications are sent to users.
    */

    'email' => [
        // Should an email be sent to a server owner once their server has completed it's first install process?
        'send_install_notification' => env('PTERODACTYL_SEND_INSTALL_NOTIFICATION', true),
        // Should an email be sent to a server owner whenever their server is reinstalled?
        'send_reinstall_notification' => env('PTERODACTYL_SEND_REINSTALL_NOTIFICATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry Settings
    |--------------------------------------------------------------------------
    |
    | This section controls the telemetry sent by Pterodactyl.
    */

    'telemetry' => [
        'enabled' => env('PTERODACTYL_TELEMETRY_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | External Websocket Proxy
    |--------------------------------------------------------------------------
    |
    | Allows tunneling external panel websocket connections through a trusted
    | local proxy that can enforce a specific Origin header.
    */
    'external_websocket_proxy' => [
        'enabled' => env('PTERODACTYL_EXTERNAL_WEBSOCKET_PROXY_ENABLED', false),
        'url' => env('PTERODACTYL_EXTERNAL_WEBSOCKET_PROXY_URL', ''),
        'secret' => env('PTERODACTYL_EXTERNAL_WEBSOCKET_PROXY_SECRET', ''),
        'origin' => env('PTERODACTYL_EXTERNAL_WEBSOCKET_PROXY_ORIGIN', env('APP_URL')),
        'ticket_ttl' => env('PTERODACTYL_EXTERNAL_WEBSOCKET_PROXY_TICKET_TTL', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | External Panel Cache
    |--------------------------------------------------------------------------
    |
    | Tune cache durations for external panel integrations.
    | Set to 0 to disable caching for a specific item.
    */
    'external_panel' => [
        'timeouts' => [
            'server_list' => env('PTERODACTYL_EXTERNAL_TIMEOUT_SERVER_LIST', 12),
            'server_list_connect' => env('PTERODACTYL_EXTERNAL_TIMEOUT_SERVER_LIST_CONNECT', 4),
            'server_detail' => env('PTERODACTYL_EXTERNAL_TIMEOUT_SERVER_DETAIL', 14),
            'server_detail_connect' => env('PTERODACTYL_EXTERNAL_TIMEOUT_SERVER_DETAIL_CONNECT', 4),
            'server_resources' => env('PTERODACTYL_EXTERNAL_TIMEOUT_SERVER_RESOURCES', 10),
            'server_resources_connect' => env('PTERODACTYL_EXTERNAL_TIMEOUT_SERVER_RESOURCES_CONNECT', 4),
        ],
        'capability_probe' => [
            // Disabled by default to avoid multiple slow probe calls on each server load.
            'enabled' => env('PTERODACTYL_EXTERNAL_CAPABILITY_PROBE_ENABLED', false),
            'timeout' => env('PTERODACTYL_EXTERNAL_CAPABILITY_PROBE_TIMEOUT', 4),
            'connect_timeout' => env('PTERODACTYL_EXTERNAL_CAPABILITY_PROBE_CONNECT_TIMEOUT', 2),
        ],
        'cache' => [
            'server_list_ttl' => env('PTERODACTYL_EXTERNAL_CACHE_SERVER_LIST_TTL', 45),
            'server_detail_ttl' => env('PTERODACTYL_EXTERNAL_CACHE_SERVER_DETAIL_TTL', 20),
            'server_resources_ttl' => env('PTERODACTYL_EXTERNAL_CACHE_SERVER_RESOURCES_TTL', 12),
            'capabilities_ttl' => env('PTERODACTYL_EXTERNAL_CACHE_CAPABILITIES_TTL', 600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy Compatibility
    |--------------------------------------------------------------------------
    |
    | Runtime toggles that allow phased removal of deprecated behavior without
    | forcing a breaking change in the same release.
    */
    'legacy' => [
        'allow_application_api_keys' => env('PTERODACTYL_ALLOW_LEGACY_APPLICATION_API_KEYS', true),
        'allow_legacy_server_configuration' => env('PTERODACTYL_ALLOW_LEGACY_SERVER_CONFIGURATION', true),
        'emit_container_oom_disabled' => env('PTERODACTYL_EMIT_CONTAINER_OOM_DISABLED', true),
    ],
];

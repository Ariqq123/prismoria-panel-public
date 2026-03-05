<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'temperature' => env('OPENAI_TEMPERATURE', 0.4),
        'system_prompt' => env(
            'OPENAI_SYSTEM_PROMPT',
            'You are the Xentra Network panel assistant. Be concise, practical, and clear.'
        ),
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
        'temperature' => env('GROQ_TEMPERATURE', 0.4),
        'system_prompt' => env('GROQ_SYSTEM_PROMPT', env('OPENAI_SYSTEM_PROMPT', '')),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'fallback_models' => env('GEMINI_FALLBACK_MODELS', 'gemini-2.5-flash,gemini-flash-latest,gemini-2.0-flash'),
        'temperature' => env('GEMINI_TEMPERATURE', 0.4),
        'system_prompt' => env('GEMINI_SYSTEM_PROMPT', env('OPENAI_SYSTEM_PROMPT', '')),
    ],

    'ai_assistant' => [
        'default_provider' => env('AI_ASSISTANT_PROVIDER', 'groq'),
        'full_server_access' => (bool) env('AI_ASSISTANT_FULL_SERVER_ACCESS', true),
    ],
];

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

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'egov' => [
        'live_mutations' => env('EGOV_ENABLE_LIVE_MUTATIONS', false),
        // Explicit sandbox|live switch. Anything other than 'live' is treated
        // as sandbox, where canned responses are allowed. See EGovMode.
        'mode' => env('EGOV_MODE', 'sandbox'),

        // Per-provider override of the switch above. Left unset a provider
        // inherits EGOV_MODE; set it to sandbox or live to pin that one
        // provider. EGOV_MODE=live with EGOV_SSO_MODE=sandbox is the demo
        // configuration: real AI, replayable sign-in.
        'modes' => [
            'sso' => env('EGOV_SSO_MODE'),
            'everify' => env('EGOV_EVERIFY_MODE'),
            'ai' => env('EGOV_AI_MODE'),
            'pay' => env('EGOV_PAY_MODE'),
            'emessage' => env('EGOV_EMESSAGE_MODE'),
            'report' => env('EGOV_REPORT_MODE'),
            'face_liveness' => env('EGOV_FACE_LIVENESS_MODE'),
            'chain' => env('EGOV_CHAIN_MODE'),
            'compass' => env('EGOV_COMPASS_MODE'),
        ],
        'sso' => [
            'partner_code' => env('EGOV_SSO_PARTNER_CODE'),
            'partner_secret' => env('EGOV_SSO_PARTNER_SECRET'),
            'base_url' => (env('EGOV_SSO_BASE_URL') && filter_var(env('EGOV_SSO_BASE_URL'), FILTER_VALIDATE_URL)) ? env('EGOV_SSO_BASE_URL') : 'http://localhost:3000/egovph/sso',
        ],
        'everify' => [
            'client_id' => env('EGOV_EVERIFY_CLIENT_ID'),
            'client_secret' => env('EGOV_EVERIFY_CLIENT_SECRET'),
            'pubkey' => env('EGOV_EVERIFY_PUBKEY'),
            'base_url' => env('EGOV_EVERIFY_BASE_URL', 'http://localhost:3000/egovph/everify'),
        ],
        'emessage' => [
            'access_token' => env('EGOV_EMESSAGE_ACCESS_TOKEN'),
            'base_url' => env('EGOV_EMESSAGE_BASE_URL', 'http://localhost:3000/egovph/emessage'),
        ],
        'ai' => [
            'access_code' => env('EGOV_AI_ACCESS_CODE'),
            'base_url' => env('EGOV_AI_BASE_URL', 'http://localhost:3000/egovph/ai'),
        ],
        'pay' => [
            'api_key' => env('EGOV_PAY_API_KEY'),
            'settlement_uuid' => env('EGOV_PAY_SETTLEMENT_UUID'),
            'base_url' => env('EGOV_PAY_BASE_URL', 'http://localhost:3000/egovph/pay'),
        ],
        'report' => [
            // No default: a baked-in access code is a published credential.
            'access_code' => env('EGOV_REPORT_ACCESS_CODE'),
            'access_token' => env('EGOV_REPORT_ACCESS_TOKEN'),
            'base_url' => env('EGOV_REPORT_BASE_URL', 'http://localhost:3000/egovph/ereport'),
        ],
        'face_liveness' => [
            'api_key' => env('EGOV_FACE_LIVENESS_API_KEY'),
            'base_url' => env('EGOV_FACE_LIVENESS_BASE_URL', 'http://localhost:3000/egovph/face-liveness'),
            // Public values served to the browser via /api/v1/egov/public-config
            // so they are declared once here instead of being copied into the
            // frontend bundle.
            'sdk_src' => env('EGOV_FACE_LIVENESS_SDK_SRC', 'https://hackathon-everify-face-liveness.e.gov.ph/js/everify-liveness-sdk.min.js'),
            'origin' => env('EGOV_FACE_LIVENESS_ORIGIN', 'https://liveness.everify.gov.ph'),
        ],
        'chain' => [
            'rpc_url' => env('EGOV_CHAIN_BASE_URL', 'http://localhost:3000/egovph/egovchain'),
            'api_key' => env('EGOV_CHAIN_API_KEY'),
            // Single source of truth for the chain id. The mock JSON-RPC
            // endpoints already reported 13371 (0x343b) while this default said
            // 2026; the adapters now derive their answer from here.
            'chain_id' => env('EGOV_CHAIN_ID', '13371'),
            'contract_address' => env('EGOV_CHAIN_CONTRACT_ADDRESS'),
        ],
        'compass' => [
            'api_key' => env('EGOV_COMPASS_API_KEY'),
            'base_url' => env('EGOV_COMPASS_BASE_URL', 'http://localhost:3000/egovph/compass'),
        ],
    ],

];

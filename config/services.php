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
            'access_code' => env('EGOV_REPORT_ACCESS_CODE', '2a72bdcac1b0405fb2c679d029f03cfb'),
            'access_token' => env('EGOV_REPORT_ACCESS_TOKEN'),
            'base_url' => env('EGOV_REPORT_BASE_URL', 'http://localhost:3000/egovph/ereport'),
        ],
        'face_liveness' => [
            'api_key' => env('EGOV_FACE_LIVENESS_API_KEY'),
            'base_url' => env('EGOV_FACE_LIVENESS_BASE_URL', 'http://localhost:3000/egovph/face-liveness'),
        ],
        'chain' => [
            'rpc_url' => env('EGOV_CHAIN_BASE_URL', 'http://localhost:3000/egovph/egovchain'),
            'api_key' => env('EGOV_CHAIN_API_KEY'),
            'chain_id' => env('EGOV_CHAIN_ID', '2026'),
            'contract_address' => env('EGOV_CHAIN_CONTRACT_ADDRESS'),
        ],
        'compass' => [
            'api_key' => env('EGOV_COMPASS_API_KEY'),
            'base_url' => env('EGOV_COMPASS_BASE_URL', 'http://localhost:3000/egovph/compass'),
        ],
    ],

];

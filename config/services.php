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
        'sso' => [
            'partner_code' => env('EGOV_SSO_PARTNER_CODE', 'HACKATHON_SSO'),
            'partner_secret' => env('EGOV_SSO_PARTNER_SECRET', '0d77fba530ee49f5b00e36fe947bd384'),
            'base_url' => (env('EGOV_SSO_BASE_URL') && filter_var(env('EGOV_SSO_BASE_URL'), FILTER_VALIDATE_URL)) ? env('EGOV_SSO_BASE_URL') : 'http://localhost:3000/egovph/sso',
        ],
        'everify' => [
            'client_id' => env('EGOV_EVERIFY_CLIENT_ID', 'a24bef86-8826-48f7-aac5-978ca5805c29'),
            'client_secret' => env('EGOV_EVERIFY_CLIENT_SECRET', '1EQT3mEC8GqEYCcUufaylPewnWi052VcJdnAOmIPHFy5zbUv0JcqVEwf7DSeb1OB'),
            'pubkey' => env('EGOV_EVERIFY_PUBKEY', 'eyJpdiI6InAzOGc3d1BZcVVZck1IY3plS0xscVE9PSIsInZhbHVlIjoiSlRESmdFYkZ4ZnV3M1ZkUjFiTHpDUT09IiwibWFjIjoiZTEzZjI5ZGRkZTVhNWNkNGU3ZmQ0NDY4MTAyZDY2Yjc1NjJiYmMxNTMwN2E2NzVlZmM5ZjhjZmEyZWM1ZmMwMCIsInRhZyI6IiJ9'),
        ],
        'emessage' => [
            'access_token' => env('EGOV_EMESSAGE_ACCESS_TOKEN', '40419e47290ae8488a0a796b7c4c66aa'),
        ],
        'ai' => [
            'access_code' => env('EGOV_AI_ACCESS_CODE', 'f2c81ce889a5850fd59487ce988ec1324183682c62d300bdbd33d5064862942b'),
        ],
        'pay' => [
            'api_key' => env('EGOV_PAY_API_KEY', 'test_bcc2092263e426957841fb633d09ec8b38b865929f2d3cb75d71f18469862885'),
            'settlement_uuid' => env('EGOV_PAY_SETTLEMENT_UUID', 'a24d6045-cf2b-4bca-9072-865c352563f5'),
        ],
        'report' => [
            'access_token' => env('EGOV_REPORT_ACCESS_TOKEN', 'ce8691fdca4e8365f2c9ec6279e3558c7e9b6387b0c5e986147e436aebeb4705'),
        ],
        'face_liveness' => [
            'api_key' => env('EGOV_FACE_LIVENESS_API_KEY', '9e31b23d-eeb4-ae08-ff13-cdf8380e5307'),
        ],
        'compass' => [
            'api_key' => env('EGOV_COMPASS_API_KEY', 'dbm_live_571ba033e980281325557042746ca1c82fca5de2350e6c6c'),
        ],
    ],

];

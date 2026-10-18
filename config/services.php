<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | SMS delivery. "log" (default) writes messages, OTP codes included, to the log channel below and
    | must not be used in production. "twilio" sends real messages and needs an upgraded account:
    | trial accounts can only send Twilio's predefined templates. Local numbers (01XXXXXXXXX) get default_country_code.
    */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'log_channel' => env('SMS_LOG_CHANNEL'),
        'default_country_code' => env('SMS_DEFAULT_COUNTRY_CODE', '880'),
        'timeout_seconds' => (int) env('SMS_TIMEOUT', 10),
    ],

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
    ],

];

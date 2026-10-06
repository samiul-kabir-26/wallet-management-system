<?php

return [
    'otp' => [
        'expiry_minutes' => (int) env('AUTH_OTP_EXPIRY_MINUTES', 5),
        'length' => (int) env('AUTH_OTP_LENGTH', 6),
        'max_attempts' => (int) env('AUTH_OTP_MAX_ATTEMPTS', 5),
    ],

    'invites' => [
        'expiry_hours' => (int) env('AUTH_INVITE_EXPIRY_HOURS', 24),
        'temp_password_length' => (int) env('AUTH_TEMP_PASSWORD_LENGTH', 16),
    ],

    'pin_reset' => [
        'expiry_minutes' => (int) env('AUTH_PIN_RESET_EXPIRY_MINUTES', 15),
    ],

    'rate_limits' => [
        'login' => (int) env('AUTH_RATE_LIMIT_LOGIN', 5),
        'register' => (int) env('AUTH_RATE_LIMIT_REGISTER', 5),
        'forgot_password' => (int) env('AUTH_RATE_LIMIT_FORGOT_PASSWORD', 5),
    ],
];

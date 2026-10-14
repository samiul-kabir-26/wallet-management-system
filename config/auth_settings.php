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

    'pin' => [
        'max_attempts' => (int) env('AUTH_PIN_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('AUTH_PIN_DECAY_SECONDS', 900),
    ],

    'tokens' => [
        // Absolute lifetime per token ability; a token never outlives this, even with refresh.
        'lifetime_minutes' => [
            'admin' => (int) env('AUTH_TOKEN_LIFETIME_ADMIN_MINUTES', 480),
            'agent' => (int) env('AUTH_TOKEN_LIFETIME_AGENT_MINUTES', 720),
            'user' => (int) env('AUTH_TOKEN_LIFETIME_USER_MINUTES', 720),
            'password-change' => (int) env('AUTH_TOKEN_LIFETIME_PASSWORD_CHANGE_MINUTES', 30),
        ],
        'default_lifetime_minutes' => (int) env('AUTH_TOKEN_DEFAULT_LIFETIME_MINUTES', 720),
        // A token unused for this long is rejected. Set to 0 to disable the idle timeout.
        'idle_timeout_minutes' => (int) env('AUTH_TOKEN_IDLE_TIMEOUT_MINUTES', 15),
    ],

    'rate_limits' => [
        'login' => (int) env('AUTH_RATE_LIMIT_LOGIN', 5),
        'register' => (int) env('AUTH_RATE_LIMIT_REGISTER', 5),
        'forgot_password' => (int) env('AUTH_RATE_LIMIT_FORGOT_PASSWORD', 5),
    ],
];

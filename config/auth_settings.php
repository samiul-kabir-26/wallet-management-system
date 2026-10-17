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
        // A token unused for this long is rejected. These are defaults: staff holding
        // settings.manage_session_timeouts override them per panel from the admin panel.
        'idle_timeout_minutes' => [
            'admin' => (int) env('AUTH_IDLE_TIMEOUT_ADMIN_MINUTES', 5),
            'agent' => (int) env('AUTH_IDLE_TIMEOUT_AGENT_MINUTES', 5),
            'user' => (int) env('AUTH_IDLE_TIMEOUT_USER_MINUTES', 5),
        ],
        // Applies to tokens without a panel ability (e.g. password-change). 0 disables it.
        'default_idle_timeout_minutes' => (int) env('AUTH_TOKEN_IDLE_TIMEOUT_MINUTES', 15),
        // Bounds staff may choose from when overriding a panel timeout.
        'idle_timeout_bounds' => ['min' => 1, 'max' => 120],
    ],

    'rate_limits' => [
        'login' => (int) env('AUTH_RATE_LIMIT_LOGIN', 5),
        'register' => (int) env('AUTH_RATE_LIMIT_REGISTER', 5),
        'forgot_password' => (int) env('AUTH_RATE_LIMIT_FORGOT_PASSWORD', 5),
        // Phone verification codes an account may request per 10 minutes
        'phone_otp' => (int) env('AUTH_RATE_LIMIT_PHONE_OTP', 3),
        // Signup codes a single IP may request per 10 minutes (no account yet, and IPs are shared)
        'phone_otp_per_ip' => (int) env('AUTH_RATE_LIMIT_PHONE_OTP_PER_IP', 10),
    ],
];

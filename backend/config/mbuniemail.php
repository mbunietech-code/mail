<?php

/*
| MbunieMail application settings. Security-relevant defaults live here so they are
| reviewable in one place; runtime overrides set by administrators are stored in
| the `settings` table (see App\Services\Settings).
*/

return [

    'organization_name' => env('MBUNIEMAIL_ORGANIZATION_NAME', 'MbuniTech'),

    // Email domains employees may log in with and link mailboxes for.
    'allowed_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MBUNIEMAIL_ALLOWED_DOMAINS', 'mt.co.tz'))
    ))),

    // Provider endpoints are configuration, never code. Confirm Hostinger values in hPanel.
    'providers' => [
        'hostinger' => [
            'imap_host' => env('IMAP_HOST'),
            'imap_port' => (int) env('IMAP_PORT', 993),
            'imap_encryption' => env('IMAP_ENCRYPTION', 'ssl'),
            'smtp_host' => env('SMTP_HOST'),
            'smtp_port' => (int) env('SMTP_PORT', 465),
            'smtp_encryption' => env('SMTP_ENCRYPTION', 'ssl'),
            'saves_sent_automatically' => (bool) env('SMTP_SAVES_SENT', false),
        ],
        'timeout_seconds' => (int) env('MBUNIEMAIL_PROVIDER_TIMEOUT', 20),
        // Local/testing only: CA bundle for the GreenMail fake server (infra/dev/tls/ca.crt).
        // Ignored in production; system CAs are always used there.
        'test_ca_file' => env('MBUNIEMAIL_TEST_CA_FILE'),
        // Local/testing only: permits provider hosts that resolve to loopback (GreenMail).
        'allow_loopback_hosts' => (bool) env('MBUNIEMAIL_ALLOW_LOOPBACK_PROVIDERS', false),
    ],

    'credentials' => [
        // Key-encryption key file for mailbox credentials. Keep it OUTSIDE the app
        // directory and out of database backups (e.g. /etc/mbuniemail/kek.json, 0600).
        'kek_path' => env('CREDENTIAL_KEK_PATH', ''),
    ],

    // Gmail is feature-flagged off for release 1 (SRS A-6): gmail.modify is a restricted
    // scope requiring Google verification and an annual CASA assessment for public apps.
    'gmail' => [
        'enabled' => (bool) env('GMAIL_ENABLED', false),
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        // Minimum scope that covers read, label/move, trash and send.
        'scopes' => ['https://www.googleapis.com/auth/gmail.modify'],
        'state_ttl_minutes' => 10,
    ],

    'accounts' => [
        'max_per_user' => (int) env('MBUNIEMAIL_MAX_ACCOUNTS_PER_USER', 5),
        // Linking attempts per user per 10 minutes — stops the endpoint being used as a
        // password-guessing oracle against the mail provider.
        'link_attempts_per_10_minutes' => 5,
    ],

    'tokens' => [
        'access_ttl_minutes' => (int) env('MBUNIEMAIL_ACCESS_TOKEN_TTL', 15),
        'refresh_absolute_ttl_days' => (int) env('MBUNIEMAIL_REFRESH_TTL_DAYS', 30),
        'refresh_idle_ttl_days' => (int) env('MBUNIEMAIL_REFRESH_IDLE_DAYS', 7),
        'mfa_pending_ttl_minutes' => 5,
        'reauth_window_minutes' => 10,
    ],

    'mfa' => [
        'issuer' => env('MBUNIEMAIL_MFA_ISSUER', 'MbunieMail'),
        // Admin roles always require MFA regardless of this flag (SRS FR-AUTH-008).
        'required_for_all' => (bool) env('MBUNIEMAIL_MFA_REQUIRED_FOR_ALL', false),
        'window' => 1,
        'recovery_code_count' => 10,
    ],

    'password' => [
        'min_length' => (int) env('MBUNIEMAIL_PASSWORD_MIN', 12),
        'check_breached' => (bool) env('MBUNIEMAIL_HIBP_ENABLED', true),
        'reset_ttl_minutes' => 30,
        // Words rejected as password components (case-insensitive).
        'context_words' => ['mbuni', 'mbunitech', 'mbuniemail', 'password'],
    ],

    'throttle' => [
        'login_per_minute' => 5,
        'login_ip_per_minute' => 20,
        'lockout_failures' => 10,
        'lockout_minutes' => 15,
        'mfa_per_minute' => 5,
        'mfa_per_hour' => 10,
        'password_reset_per_hour' => 3,
        'api_per_minute' => 120,
    ],

    'invitations' => [
        'ttl_hours' => 72,
    ],

    // Deep-link / web base that password-reset and invitation links point to.
    'app_link_url' => rtrim((string) env('MBUNIEMAIL_APP_LINK_URL', 'https://localhost/app'), '/'),

    'audit' => [
        'retention_days' => (int) env('MBUNIEMAIL_AUDIT_RETENTION_DAYS', 730),
    ],
];

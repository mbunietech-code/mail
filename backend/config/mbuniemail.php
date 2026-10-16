<?php

/*
| MbunieMail application settings. Security-relevant defaults live here so they are
| reviewable in one place; runtime overrides set by administrators are stored in
| the `settings` table (see App\Services\Settings).
*/

return [

    'organization_name' => env('MBUNIEMAIL_ORGANIZATION_NAME', 'MbunieTech'),

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
        // directory and out of database backups (e.g. /etc/mbuniemail/keys/kek.json, 0600, owned by the app user).
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

    // Synchronisation (SRS §9, FR-SYNC-*). Only metadata is cached (SDD ADR-002).
    'sync' => [
        'window_days' => (int) env('MBUNIEMAIL_SYNC_WINDOW_DAYS', 30),
        'initial_limit' => (int) env('MBUNIEMAIL_SYNC_INITIAL_LIMIT', 2000),
        'batch_size' => 200,
        'max_batches_per_run' => 5,
        'inbox_interval_minutes' => 2,
        'other_folders_interval_minutes' => 15,
        'idle_interval_minutes' => 15,   // no client activity for 7 days
        'dormant_interval_minutes' => 60, // no client activity for 30 days
        'backoff_base_seconds' => 30,
        'backoff_cap_seconds' => 1800,
        'cache_snippets' => (bool) env('MBUNIEMAIL_CACHE_SNIPPETS', true),
    ],

    'outbox' => [
        'max_recipients' => (int) env('MBUNIEMAIL_MAX_RECIPIENTS', 100),
        'max_attachment_bytes' => (int) env('MBUNIEMAIL_MAX_ATTACHMENT_BYTES', 25 * 1024 * 1024),
        'max_html_bytes' => 2 * 1024 * 1024,
        'messages_per_hour' => (int) env('MBUNIEMAIL_SEND_PER_HOUR', 100),
        'recipients_per_hour' => (int) env('MBUNIEMAIL_RECIPIENTS_PER_HOUR', 500),
        'max_attempts' => 5,
        // 'warn' flags risky attachment types; 'block' refuses them (SRS FR-SEND-003).
        'risky_extension_policy' => env('MBUNIEMAIL_RISKY_ATTACHMENT_POLICY', 'warn'),
        'risky_extensions' => ['exe', 'scr', 'bat', 'cmd', 'com', 'pif', 'js', 'jse', 'vbs', 'vbe', 'wsf', 'ps1', 'msi', 'msp', 'jar', 'lnk', 'hta', 'iso', 'img', 'reg', 'cpl', 'dll'],
        'payload_retention_hours' => 24,
        'failed_retention_days' => 7,
    ],

    'image_proxy' => [
        'max_bytes' => 5 * 1024 * 1024,
        'timeout_seconds' => 5,
        'max_redirects' => 3,
        'allowed_types' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],
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
        // Everyone sets up two-step verification at first sign-in; admins can relax
        // this in Policies, but admin roles always require MFA (SRS FR-AUTH-008).
        'required_for_all' => (bool) env('MBUNIEMAIL_MFA_REQUIRED_FOR_ALL', true),
        'window' => 1,
        'recovery_code_count' => 10,
    ],

    'password' => [
        'min_length' => (int) env('MBUNIEMAIL_PASSWORD_MIN', 12),
        'check_breached' => (bool) env('MBUNIEMAIL_HIBP_ENABLED', true),
        'reset_ttl_minutes' => 30,
        // Words rejected as password components (case-insensitive).
        'context_words' => ['mbuni', 'mbunie', 'mbunitech', 'mbunietech', 'mbuniemail', 'password'],
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
    // Defaults to the backend's own /app pages (OnboardingController).
    'app_link_url' => rtrim((string) env('MBUNIEMAIL_APP_LINK_URL', rtrim((string) env('APP_URL', 'https://localhost'), '/').'/app'), '/'),

    // Detective controls (docs/OPERATIONS_RUNBOOK.md §3). Email goes to the security owner.
    'alerts' => [
        'email' => env('MBUNIEMAIL_ALERT_EMAIL', ''),
        'log_channel' => env('MBUNIEMAIL_ALERT_LOG_CHANNEL', 'stack'),
        'failed_login_threshold' => (int) env('MBUNIEMAIL_ALERT_FAILED_LOGINS', 20),
    ],

    'audit' => [
        'retention_days' => (int) env('MBUNIEMAIL_AUDIT_RETENTION_DAYS', 730),
        // Production: "audit_maint" (DB account with DELETE on audit_logs only).
        'maint_connection' => env('MBUNIEMAIL_AUDIT_MAINT_CONNECTION', 'mariadb'),
    ],

    // Android push (FR-NOTIF-001). Path to a Firebase service-account JSON (mode 0600).
    // Empty = push disabled; the app still shows new mail when opened.
    'push' => [
        'fcm_credentials' => env('MBUNIEMAIL_FCM_CREDENTIALS', ''),
    ],

    'users' => [
        // Personal data of deactivated users is deleted after this many days unless on legal hold.
        'deactivated_retention_days' => (int) env('MBUNIEMAIL_DEACTIVATED_RETENTION_DAYS', 30),
    ],
];

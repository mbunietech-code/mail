# MbunieMail — Environment Variables

The backend reads configuration from `backend/.env` (never committed; `chmod 600`). Template: [`backend/.env.example`](../backend/.env.example) (placeholders only).
All values are read through `config/*.php`, so they keep working after `php artisan config:cache`. Code never calls `env()` outside `config/` (enforced by Larastan).

## Core
| Variable | Required | Default | Production value / notes |
|---|---|---|---|
| `APP_NAME` | yes | MbunieMail | |
| `APP_ENV` | yes | local | `production` |
| `APP_KEY` | yes | — | `php artisan key:generate`. Encrypts TOTP secrets, session data, and cached subjects/snippets. Rotate via `APP_PREVIOUS_KEYS` |
| `APP_DEBUG` | yes | true | **must be `false`** |
| `APP_URL` | yes | | `https://<api-host>` |
| `LOG_LEVEL` | no | debug | `info` |
| `LOG_STACK` | no | daily | `daily` (with logrotate) |

## Database and Redis
| Variable | Notes |
|---|---|
| `DB_CONNECTION` | `mariadb` |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` on the server (dev Docker uses 3307) |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | Application user with least privilege (no FILE, no GRANT; INSERT+SELECT only on `audit_logs`) |
| `REDIS_CLIENT` | `predis` (pure PHP) or `phpredis` if the extension is installed |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` | Redis bound to localhost with `requirepass` (dev Docker uses 6380) |
| `QUEUE_CONNECTION` | `redis` (`database` is a dev fallback) |
| `CACHE_STORE` | `redis`. Rate limiters and the reauth window live here |
| `SESSION_DRIVER` / `SESSION_LIFETIME` / `SESSION_ENCRYPT` / `SESSION_SECURE_COOKIE` | Admin web sessions: `redis` / `30` / `true` / `true` |

## Security and auth
| Variable | Default | Notes |
|---|---|---|
| `HASH_DRIVER` | argon2id | Password and recovery-code hashing (tests use bcrypt for speed) |
| `SANCTUM_TOKEN_PREFIX` | mbm_ | Makes leaked access tokens detectable by secret scanners. Refresh tokens use `mbr_` |
| `TRUSTED_PROXIES` | (empty) | Comma-separated proxy IPs allowed to set `X-Forwarded-*`. Production behind local Nginx: `127.0.0.1,::1`. **Never `*`** |
| `CORS_ALLOWED_ORIGINS` | (empty) | Native apps need no CORS. Leave empty |
| `MBUNIEMAIL_ACCESS_TOKEN_TTL` | 15 | Minutes |
| `MBUNIEMAIL_REFRESH_TTL_DAYS` | 30 | Absolute session lifetime |
| `MBUNIEMAIL_REFRESH_IDLE_DAYS` | 7 | Idle timeout |
| `MBUNIEMAIL_RESET_TTL` | 30 | Password-reset token lifetime (minutes) |
| `MBUNIEMAIL_HIBP_ENABLED` | true | Breached-password check (k-anonymity, only 5 hash characters leave the server; fails open and logs if the service is unreachable) |
| `MBUNIEMAIL_MFA_REQUIRED_FOR_ALL` | false | Default org-wide MFA policy (admins always require MFA). Admins can override it at runtime (`settings` table) |
| `MBUNIEMAIL_AUDIT_RETENTION_DAYS` | 730 | |

## Organisation and links
| Variable | Notes |
|---|---|
| `MBUNIEMAIL_ORGANIZATION_NAME` | `MbuniTech` |
| `MBUNIEMAIL_ALLOWED_DOMAINS` | Comma-separated, e.g. `mt.co.tz`. Login invitations and mailbox linking are restricted to these |
| `MBUNIEMAIL_APP_LINK_URL` | Base of reset and invitation links (Android App Link / web handler). Tokens are placed in the URL fragment |

## Mail provider (Hostinger)
| Variable | Observed 2026-10-14 (confirm in hPanel) |
|---|---|
| `IMAP_HOST` / `IMAP_PORT` / `IMAP_ENCRYPTION` | `imap.hostinger.com` / `993` / `ssl` |
| `SMTP_HOST` / `SMTP_PORT` / `SMTP_ENCRYPTION` | `smtp.hostinger.com` / `465` / `ssl` (or `587` / `starttls`) |
| `SMTP_SAVES_SENT` | `false` until verified with a test mailbox |

Changing these requires `php artisan db:seed --class=ProviderProfileSeeder` (or, later, the admin UI).

## Notification mail (MbunieMail's own email)
`MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`: the account used to send password-reset and invitation emails (e.g. a dedicated `no-reply@` Hostinger mailbox in production; Mailpit in dev).

## Mailbox credentials and providers
| Variable | Default | Notes |
|---|---|---|
| `CREDENTIAL_KEK_PATH` | — (required) | Path to the key-encryption-key file for mailbox passwords and OAuth tokens. **Outside** the application directory, owner-only (`chmod 600`; the app refuses looser permissions), and **excluded from database backups**. Created and rotated with `php artisan mbuniemail:kek-rotate`, then `mbuniemail:kek-rewrap`. Losing it makes stored mailbox credentials unrecoverable: users must re-enter them |
| `MBUNIEMAIL_PROVIDER_TIMEOUT` | 20 | Seconds for IMAP/SMTP socket operations |
| `MBUNIEMAIL_MAX_ACCOUNTS_PER_USER` | 5 | |

## Sync and outbox
| Variable | Default | Notes |
|---|---|---|
| `MBUNIEMAIL_SYNC_WINDOW_DAYS` | 30 | Initial sync caches metadata for this many days per folder |
| `MBUNIEMAIL_SYNC_INITIAL_LIMIT` | 2000 | Max messages per folder on the first sync |
| `MBUNIEMAIL_CACHE_SNIPPETS` | true | `false` removes the 200-character previews from the server cache (privacy over convenience) |
| `MBUNIEMAIL_MAX_RECIPIENTS` | 100 | Per message (To + Cc + Bcc) |
| `MBUNIEMAIL_MAX_ATTACHMENT_BYTES` | 26214400 | 25 MB per message; keep at or below Hostinger's limit and Nginx `client_max_body_size` |
| `MBUNIEMAIL_SEND_PER_HOUR` / `MBUNIEMAIL_RECIPIENTS_PER_HOUR` | 100 / 500 | Per user and per account, so a stolen session cannot spam (risk R-11). Keep below Hostinger's own limits |
| `MBUNIEMAIL_RISKY_ATTACHMENT_POLICY` | warn | `warn` flags executables and double extensions; `block` refuses them on upload |

## Gmail (feature flag, off for release 1)
| Variable | Notes |
|---|---|
| `GMAIL_ENABLED` | `false` until Google verification for the restricted `gmail.modify` scope is approved |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | OAuth "Web application" client from Google Cloud Console. Secret only in `.env` |
| `GOOGLE_REDIRECT_URI` | Must exactly equal the URI registered at Google: `https://<api-host>/api/v1/oauth/google/callback` |

## Local development and testing only
Ignored outside `local` and `testing` environments (enforced in code).
| Variable | Notes |
|---|---|
| `MBUNIEMAIL_TEST_CA_FILE` | `infra/dev/tls/ca.crt`, the throwaway CA for the GreenMail fake server, so tests keep certificate verification **on** |
| `MBUNIEMAIL_ALLOW_LOOPBACK_PROVIDERS` | `true` lets provider hosts resolve to 127.0.0.1 (GreenMail). Never honoured in production |
| `GREENMAIL_PASSWORD` | Password of the fake `test1@`/`test2@mt.co.tz` GreenMail users (same as `DEV_GREENMAIL_PASSWORD` in `infra/.env`) |

## Planned
`FCM_*` (Android push, Phase 5/6).

## Dev-only: `infra/.env`
Holds `DEV_DB_ROOT_PASSWORD`, `DEV_DB_PASSWORD` and `DEV_REDIS_PASSWORD` for `infra/docker-compose.dev.yml`. Random values are generated locally and gitignored. Template: `infra/.env.example`.

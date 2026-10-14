# MbunieMail — Test Results Log

Only tests that were actually executed are recorded here.

## 2026-10-14 — Phase 4 (email provider layer)
Environment: as Phase 3, plus GreenMail 2.1.3 fake IMAP/SMTP (TLS with a certificate from the throwaway dev CA, authentication enforced). Command: `infra/scripts/check-backend.sh`

| Check | Result |
|---|---|
| Pint | ✅ pass |
| PHPStan / Larastan level 6 | ✅ 0 errors (17 found and fixed, including a wrong model type that made PHPStan treat OAuth completion as unreachable) |
| PHPUnit | ✅ **124 tests, 754 assertions, 0 failures** (Unit 41, Feature 75, Integration 8) |
| SEC-T25 log canaries (now including mailbox password, OAuth tokens and client secret, GreenMail password) | ✅ pass |
| `composer audit` | ✅ no advisories |
| gitleaks (committable files) | ✅ no leaks |
| openapi.yaml lint | ✅ 0 errors |

### Integration tests against GreenMail (real TLS, no real mailboxes)
Verify over verified TLS ✅ · wrong password gives `AUTH_FAILED` ✅ · untrusted certificate gives `TLS_ERROR` for IMAP **and** SMTP ✅ · **no plaintext downgrade** when STARTTLS is unavailable (SMTP and IMAP) ✅ · send, receive, sync, read, attachment round trip ✅ · sync and read never set `\Seen` ✅ · incremental sync, flag refresh, move and expunge detection ✅ · append to Sent and role detection ✅ · hostile search input ✅.

### Security matrix tests added
SEC-T13 (accounts: show, update, diagnostics, folders, credential, delete, and admin role), SEC-T14 (header injection, BCC not in headers), SEC-T18 (provider host SSRF: RFC1918, metadata, CGNAT, ULA, link-local, IPv4-mapped, multicast, mixed records, loopback only in non-production), SEC-T24 (credential AAD binding plus tamper), SEC-T26 (OAuth state replay, forgery, expiry, and **account-linking CSRF**), SEC-T27 (revocation at Google on unlink), SEC-T35 (filename traversal), SEC-T45 (unknown-outcome classification), SEC-T51 (no tokens or code in redirects). Also: link attempts are rate-limited (no password oracle), the KEK file permission check, fail-closed on a missing KEK, and Secret non-leakage.

### Mutation checks
| Deliberate defect | Detected by |
|---|---|
| Credential sealed without the account-id AAD | CredentialVaultTest (2 failures) |
| Account lookup not scoped to the owner | AccountLinkTest (2 failures) |
| Gmail completion not bound to the initiating session | GmailOAuthTest (1 failure) |
| TLS `verify_peer` turned off | GreenMail integration (1 failure) |
| "Unknown outcome" classification removed | MailboxHelpersTest (1 failure) |

### Defects found in this phase
1. **`webklex/php-imap` could not parse BODYSTRUCTURE or `BODY.PEEK[...]` responses.** Replaced with `directorytree/imapengine` (ADR-004 revised).
2. **ImapEngine discarded TLS error details**, so certificate failures looked like "unreachable". The hardened connection now captures the OpenSSL warning (narrowly matched, so refused connections stay `UNREACHABLE`).
3. **GreenMail auth was silently disabled** by `-Dgreenmail.auth.disabled=false` (the flag's presence disables auth). This would have made auth-failure tests meaningless. Fixed in the dev compose file, then verified.
4. Token expiry used `time()` instead of the application clock. Fixed (consistent with test time travel).
5. Mass-assignment protection blocked `firstOrNew()` on guarded models. Switched to explicit lookups rather than loosening guards.

### Not yet executed
Live Hostinger verification (awaiting test mailboxes and authorization), real Google OAuth (feature-flagged off; needs a Google Cloud project), sync jobs and scheduler (Phase 5).

## 2026-10-14 — Phase 3 (foundation)
Environment: Kali Linux dev host, PHP 8.4.24, Laravel 13.36.0, MariaDB 11.8 + Redis 7 (Docker, `infra/docker-compose.dev.yml`). No commit yet (repository has no commits).
Command: `infra/scripts/check-backend.sh`

| Check | Result |
|---|---|
| Pint (format check) | ✅ pass |
| PHPStan / Larastan level 6 (`app`, `config`, `database`, `routes`) | ✅ 0 errors (11 found and fixed on first run, including `env()` outside config) |
| PHPUnit | ✅ **62 tests, 369 assertions, 0 failures** |
| SEC-T25 log canary scan | ✅ pass (failed on first run: exception messages bypassed redaction, now fixed and covered by a unit test) |
| `composer audit` | ✅ no advisories |
| gitleaks (files git would commit) | ✅ no leaks. Detection verified by planting a fake key (found), then removing it |
| Migrations | ✅ `migrate`, `migrate:rollback` (all batches), re-`migrate`, `migrate:fresh` |
| `config:cache` | ✅ trusted proxies and provider hosts resolve correctly when cached |
| openapi.yaml (Redocly CLI lint) | ✅ 0 errors, 25 warnings (cosmetic: license, tag descriptions, some operations without a 4xx response) |

### Security matrix tests executed in this phase
SEC-T01, T02, T03, T04, T05, T06, T07, T08, T09, T10, T11 (route classification subset), T12, T13 (session subset), T25, T31, T32, T33, T34, T40, T48. Plus CORS-deny, request-id and suspended-user cut-off tests.

### Mutation checks (do the tests catch regressions?)
| Deliberate defect | Detected by |
|---|---|
| Removed `token:mail` gate from full-access routes | RouteSweepTest (2 failures) |
| Disabled refresh-token reuse detection | RefreshTokenTest (1 failure) |
| Re-introduced the TOTP null-baseline replay bug | MfaTest (1 failure) |

### Defects found by tests in this phase
1. **TOTP replay (security):** `google2fa::verifyKeyNewer()` returns `true` instead of the time-step when the baseline is `null`. The first code after enrolment could be replayed. Fixed by using baseline 0, with a regression test.
2. **Secrets in exception logs (security):** the log redactor scrubbed messages and context strings, but not `Throwable` objects. Fixed by flattening exceptions inside the processor.
3. **Trusted proxies lost under `config:cache` (security and availability):** `env()` in `bootstrap/app.php` returns null when config is cached, which would merge all clients behind Nginx into one IP for rate limiting. Moved to `config/trustedproxy.php`.
4. **Provider seeder used `env()`** (would seed empty hosts in production). Moved to config.

### Not yet executed
Everything mail-related (Phases 4–5), Flutter (Phase 6), admin UI (Phase 7), TLS/port scans and DR drills (staging), and live Hostinger tests (awaiting authorization and test mailboxes).

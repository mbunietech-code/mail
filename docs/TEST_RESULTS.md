# MbunieMail — Test Results Log

Only tests that were actually executed are recorded here.

## 2026-10-14 — Phase 7 (administration panel), branch `phase-7-admin`
Command: `infra/scripts/check-backend.sh`. Result: ✅ Pint · ✅ PHPStan L6 (0 errors) · ✅ **230 tests, 0 failures** · ✅ log canaries · ✅ composer audit · ✅ gitleaks.

New suite `AdminPanelTest` (23 tests):
- password then TOTP sign-in, with a generic refusal for employees and wrong passwords;
- forced MFA enrolment for console-created admins; a non-admin session is thrown out;
- session ID regenerated at each privilege step;
- role and permission matrix (SEC-T11);
- reauthentication for every write;
- suspend revokes tokens (re-activation does not revive them); deactivate disables mailboxes;
- no self-modification; the last super admin is protected; only super admins grant admin roles;
- invitations, MFA reset;
- **no mail content visible anywhere in the panel** (US-07);
- audit filters, chain verification, CSV export (audited, spreadsheet formula injection neutralised);
- policy changes take effect and are audited;
- mail-server hosts are refused if they resolve to internal addresses;
- failed-job payloads hidden;
- CSRF enforced (SEC-T17, with the unit-test bypass removed);
- strict CSP (no inline script or style), `X-Frame-Options: DENY`, SameSite=Strict, HttpOnly, 30-minute idle sessions.

### Mutation checks: all 8 detected
Admin-role check removed · MFA step skippable · reauth bypassed · role escalation allowed · self-modification allowed · CSV formula escaping removed · provider host check removed · suspend without token revocation.
Two mutations initially **survived**. Both exposed weak tests rather than missing controls, and both tests were fixed:
- the self-modification test happened to be blocked by the "last super admin" rule;
- the token-revocation test was masked by the status check, and the reactivation step used a stale in-memory model.

### Defects found in this phase
1. Laravel 13 ships no plain pagination view. Added a CSP-compatible one.
2. Company domains were read from two sources (config and the database). Unified on the database (`CompanyDomains`), so admin edits apply everywhere.
3. A Laravel debug error page appeared in a failing test run. It confirms `APP_DEBUG=false` is mandatory in production (hardening checklist).
4. **Found by a real-browser check, invisible to tests:** the stylesheet lived in `public/admin/`, so the web server served that directory for `GET /admin` (404) instead of the panel. The same would happen behind Nginx `try_files $uri $uri/`. Moved to `public/assets/`; `PublicPathCollisionTest` now fails if any public directory shadows a route prefix. Verified it fails with the directory present.

Browser check (built-in browser, `php artisan serve`): sign-in page renders with the stylesheet under the strict CSP, no console CSP violations, `/admin` redirects to sign-in. Final count: **230 tests passing**.

## 2026-10-14 — Phase 5 (core mail features), branch `phase-5-core-mail`
Command: `infra/scripts/check-backend.sh` (GreenMail running)

| Check | Result |
|---|---|
| Pint | ✅ pass |
| PHPStan / Larastan level 6 | ✅ 0 errors (8 found and fixed) |
| PHPUnit | ✅ **206 tests, 1421 assertions, 0 failures** |
| SEC-T25 log canaries | ✅ pass |
| `composer audit` | ✅ no advisories |
| gitleaks (committable files) | ✅ no leaks |

New suites: `EmailHtmlSanitizerTest` (30, including a 25-vector XSS corpus), `SyncTest` (11), `MessageApiTest` (16), `ImageProxyTest` (5), `OutboxTest` (16), `ContactsPreferencesNotificationsTest` (3), plus an `\Answered` assertion against GreenMail.

### Security matrix tests added or extended
SEC-T13 (11 message, attachment, thread, search, sync and folder routes, plus bulk and cross-account move), SEC-T16 (sanitiser corpus, contents of dangerous containers removed, end-to-end through the read API), SEC-T18 (image proxy: internal IPs, metadata, loopback, IPv6 loopback, non-standard port, `file://`, credentials in URL, redirect to internal host), SEC-T19 (unsigned, tampered, expired, unauthenticated), SEC-T22 (messages per hour, recipients per hour), SEC-T23 (From override ignored), SEC-T35/T36/T37 (upload filename, size, type mismatch, risky-extension warn and block), SEC-T38 (notification privacy), SEC-T44 (claimed message not resubmitted; double run submits once), SEC-T45 (unknown outcome not retried; resend needs explicit confirmation), SEC-T46 (idempotent replay; body mismatch gives 409).

### Mutation checks
| Deliberate defect | Detected by |
|---|---|
| Message lookup not scoped to owner | MessageApiTest |
| Outbox claim not conditional on `queued` | OutboxTest (2 failures) |
| Unknown outcome handled like a retryable error | OutboxTest |
| Image proxy signature check bypassed | ImageProxyTest |
| Image proxy host guard bypassed | ImageProxyTest (2 failures) |
| Idempotency body check removed | OutboxTest |
| Expunge detection removed | SyncTest |
| Sanitiser drop list without `button`, `video`, `audio` or `dialog` (elements Symfony considers safe) | EmailHtmlSanitizerTest |
| Drop list without `script`, `style`, `form`, `select`, `option` or `textarea` | *Not detected, by design:* Symfony's default action already drops these with their contents. The explicit entries are a second layer |

### Defects found in this phase
1. **Image proxy accepted loopback in dev/test:** the GreenMail loopback exception applied to every HostGuard caller. Production was unaffected (the flag is ignored there), but the proxy now refuses loopback in every environment, with a unit test.
2. **Inline `cid:` images were dropped** because Symfony's URL sanitizer rejects `cid:`. Replaced it with a guard that keeps Symfony's checks for every other URL attribute. `withoutAttributeSanitizer()` removes by instance identity, which needed the actual instance.
3. **"Answered" was only set in the cache**, so the next sync reverted it. It is now stored on the server (`\Answered`), verified against GreenMail.
4. **Found in review before any test, then fixed:** a post-send failure (for example while copying to Sent) could have relabelled a delivered message `failed`, inviting a duplicate resend; and a worker crash could leave messages in `sending` forever. Both fixed: delivered stays delivered, and stale `sending` becomes `unknown`.
5. Sanitiser test gap: dangerous-container contents were not asserted. Strengthened.

### Not yet executed
Live Hostinger (awaiting test mailboxes), real Google OAuth, queue workers under production supervisors, load and performance tests (k6), and Flutter (Phase 6).

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

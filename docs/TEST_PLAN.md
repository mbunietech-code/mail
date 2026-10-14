# MbunieMail — Test Plan

Version 0.1 · 2026-10-14

## 1. Principles
- Automated tests **never** touch real mailboxes or send real email. Providers are replaced by `FakeProvider` (unit and feature tests) or by local **GreenMail** (IMAP + SMTP) and **Mailpit** containers (integration tests).
- Live Hostinger tests run **only** in an explicitly authorized test environment (`LIVE_PROVIDER_TESTS=1`) against **dedicated test mailboxes** created for that purpose (for example `mbuniemail-test1@mt.co.tz`), never `ceo@` or other production mailboxes.
- A test counts as passed only if it was actually executed. The results log (§9) records the command, date, environment and outcome.

## 2. Levels and tools
| Level | Scope | Tools |
|---|---|---|
| Unit (backend) | Services: sanitizer, threading, backoff, vault, TOTP, outbox state machine | PHPUnit / Pest |
| Feature (backend) | HTTP endpoints, policies, validation, rate limits, audit | Laravel HTTP tests, MariaDB test DB (`mbuniemail_test`) |
| Architecture | Layer rules (controllers don't call providers directly; admin read models don't select content columns) | Pest arch tests |
| Integration (provider) | ImapSmtpProvider against GreenMail. SMTP capture with Mailpit | Docker Compose (`infra/docker-compose.dev.yml`) |
| Gmail integration | OAuth flow and API calls against an HTTP fake (`Http::fake`) | Laravel HTTP fake |
| Flutter unit/widget | Repositories, state notifiers, widgets, HTML renderer rules | `flutter test` |
| Flutter integration | Login → inbox → read → compose against the dev backend | `integration_test` on an Android emulator and Windows |
| Accessibility | Contrast, semantics, tap targets, keyboard | Flutter `meetsGuideline` matchers, manual TalkBack and Narrator check |
| Performance | API P95, sync throughput | k6 (staging), Laravel query counters |
| Security | Security test matrix (§4), SAST, dependency audit, secret scan, TLS scan | PHPStan/Larastan, `composer audit`, `dart analyze`, gitleaks, testssl.sh, OWASP ZAP baseline (staging) |
| DR | Backup / restore drill | scripts in `infra/scripts`, manual timing |

## 3. Functional coverage (traceability excerpt)
| Requirement | Tests |
|---|---|
| FR-AUTH-001/002/003 | `LoginTest`, `LogoutTest`, `SessionRevocationTest` |
| FR-AUTH-004 | `PasswordResetTest` (generic response, expiry, single-use, revocation) |
| FR-AUTH-007/008/009 | `MfaEnrollmentTest`, `MfaLoginTest`, `MfaPolicyTest`, `RecoveryCodeTest` |
| FR-AUTH-010 | `ReauthMiddlewareTest` |
| FR-AUTH-011 | `PasswordPolicyTest` (HIBP faked) |
| FR-ACCT-001/003/004 | `LinkAccountTest` (FakeProvider success, auth fail, timeout), `DiagnosticsTest` |
| FR-ACCT-005 | `GmailOAuthTest` (state, PKCE, scope check, revoke) |
| FR-MAIL-001..011 | `FolderMappingTest`, `MessageListTest`, `MessageReadTest`, `FlagsTest`, `MoveTest`, `SearchTest`, `ThreadingTest`, `BulkActionTest` |
| FR-SEND-001..009 | `SendTest`, `ReplyHeadersTest`, `AttachmentUploadTest`, `OutboxIdempotencyTest`, `OutboxStateMachineTest`, `SendRateLimitTest` |
| FR-SYNC-001..006 | `InitialSyncTest`, `IncrementalSyncTest`, `UidValidityChangeTest`, `DuplicatePreventionTest`, `BackoffTest`, `CheckpointResumeTest` |
| FR-NOTIF-002 | `NotificationPayloadTest` |
| FR-ADM-* | `Admin\*Test` (invite, suspend, roles, MFA policy, audit view, session revoke, health) |
| FR-UI-* | Flutter widget tests: adaptive layout breakpoints, states, keyboard shortcuts, DEV DATA banner absent in prod flavor |

## 4. Security test matrix
Every High or Critical inherent risk maps to at least one automated test.

| Test ID | Risk | Description | Type |
|---|---|---|---|
| SEC-T01 | R-01, R-16 | Login throttle: 6th attempt per minute returns 429. Lockout after 10 per hour | Feature |
| SEC-T02 | R-01 | Login of an MFA-enabled user returns `mfa_required`. No tokens with `mail` ability | Feature |
| SEC-T03 | R-01 | Breached password rejected (HIBP fake) | Feature |
| SEC-T04 | R-02 | Reset token single-use | Feature |
| SEC-T05 | R-02 | Reset token expires after 30 min | Feature |
| SEC-T06 | R-02 | Reset revokes all refresh sessions | Feature |
| SEC-T07 | R-03 | Rotated refresh token reuse revokes family, writes audit | Feature |
| SEC-T08 | R-03 | Expired access token returns 401 | Feature |
| SEC-T09 | R-04 | Route sweep: every `mail` route rejects an `mfa-pending` token | Feature (iterates route list) |
| SEC-T10 | R-04 | TOTP code replay in the same step rejected | Unit |
| SEC-T11 | R-05 | Route × role permission matrix | Feature |
| SEC-T12 | R-05 | Mass assignment (`role`, `status`, `email`) on PATCH /me ignored | Feature |
| SEC-T13 | R-06 | Cross-account isolation: user B against every user-A resource route returns 404 | Feature (generated) |
| SEC-T14 | R-07 | CRLF in subject, recipients and search is rejected or encoded. No injected header in MIME | Unit + Feature |
| SEC-T15 | R-07 | SQLi payload corpus on query params: no 500, no data leak | Feature |
| SEC-T16 | R-08 | XSS corpus through the sanitizer: no `<script>`, `on*=`, `javascript:`, `<iframe>`, `<form>`, `<svg>`, `<base>`, `<meta>` | Unit |
| SEC-T17 | R-08 | Admin POST without CSRF returns 419 | Feature |
| SEC-T18 | R-09 | Image proxy denies 127.0.0.1, 10/8, 169.254/16, ::1, ::ffff:127.0.0.1, redirect-to-private, non-image types | Unit + Feature (`Http::fake`) |
| SEC-T19 | R-09 | Image proxy rejects a missing, tampered or expired signature | Feature |
| SEC-T20 | R-10 | `invoice.pdf.exe` and MIME mismatch flagged | Unit |
| SEC-T21 | R-10 | EICAR flagged when ClamAV is enabled (skipped and recorded if not) | Integration |
| SEC-T22 | R-11 | Send rate limit enforced per user and account | Feature |
| SEC-T23 | R-11 | Request cannot override From | Feature |
| SEC-T24 | R-12 | Vault: ciphertext moved to another account_id fails to decrypt (AAD) | Unit |
| SEC-T25 | R-12, R-19 | After the full suite, logs contain none of the canary secrets used in tests | CI step |
| SEC-T26 | R-13, R-29 | OAuth state: missing, reused, expired or other-user state rejected | Feature |
| SEC-T27 | R-13 | Gmail disconnect calls the revoke endpoint and deletes tokens | Feature |
| SEC-T28 | R-14 | Static check: tokens only via the SecureStorage abstraction | Flutter test + grep in CI |
| SEC-T29 | R-14 | Logout / 401-revoked wipes the local cache and secure storage | Flutter unit |
| SEC-T30 | R-15 | API limiter returns 429 with Retry-After | Feature |
| SEC-T31 | R-15 | Spoofed `X-Forwarded-For` from an untrusted IP is ignored | Feature |
| SEC-T32 | R-16 | MFA limiter | Feature |
| SEC-T33 | R-17 | Login and forgot-password responses identical for unknown and known users | Feature |
| SEC-T34 | R-17 | 500 in production mode returns generic problem+json with no trace | Feature |
| SEC-T35 | R-18 | Path traversal filename sanitized | Unit |
| SEC-T36 | R-18 | Oversize upload returns 413 or 422 | Feature |
| SEC-T37 | R-18 | MIME mismatch flagged | Unit |
| SEC-T38 | R-19 | Push payload has no subject or body by default | Unit |
| SEC-T39 | R-20, R-28 | `composer audit` / `npm audit` / OSV: no high or critical | CI |
| SEC-T40 | R-21 | Security headers present on API and admin responses | Feature |
| SEC-T41 | R-21 | testssl.sh against staging | Manual / staging |
| SEC-T42 | R-22 | External port scan shows only 22/80/443 | Staging |
| SEC-T43 | R-22 | Backup encrypted and unreadable without the key | DR script |
| SEC-T44 | R-23 | Concurrent claim of an outbox item: exactly one submission | Feature |
| SEC-T45 | R-23 | Timeout after DATA moves to `unknown` with no retry | Unit |
| SEC-T46 | R-23 | Idempotency-Key replay returns the same record. A different body returns 409 | Feature |
| SEC-T47 | R-24 | Admin-role token gets 404 on other users' content | Feature |
| SEC-T48 | R-24 | Audit hash-chain verification detects a modified row | Unit |
| SEC-T49 | R-25 | DNS policy check script (SPF, DKIM, DMARC) | Release checklist |
| SEC-T50 | R-26 | Provider throttled or unreachable sets account status and backoff, and the UI banner shows | Feature + Flutter widget |
| SEC-T51 | R-29 | OAuth callback rejects unknown state. Redirect contains no code or token | Feature |
| DR-T01 | R-27 | Full restore drill within RTO | Manual, recorded |
| DR-T02 | R-27 | Decrypt a credential after restore with the escrowed KEK | DR script |
| PRIV-T01 | R-30 | Purge job removes account data | Feature |
| PRIV-T02 | R-30 | Audit retention job | Feature |

## 5. Hostinger integration tests (authorized environment only)
Preconditions: written authorization; dedicated test mailboxes; credentials in the CI secret store or a local `.env.testing.live`, never in the repo.
1. Verify IMAP login (993/SSL) and SMTP login (465/SSL and 587/STARTTLS).
2. Confirm SPECIAL-USE folder attributes and capability list (MOVE, CONDSTORE, UIDPLUS, IDLE).
3. Send test1 → test2. Verify a single delivery and the Sent-copy behaviour (decides `saves_sent_automatically`).
4. Flag, move and delete round-trip.
5. Measure connection and throttle behaviour (note limits; don't stress production infrastructure).

## 6. Flutter tests
- Unit: repositories with a mocked Dio adapter, token refresh single-flight, error mapping.
- Widget: inbox list states (loading, empty, error, offline), reader blocks remote images, link-confirm sheet, composer validation, adaptive layouts at 360/800/1400 widths, dark theme contrast.
- Integration (Android emulator API 34 and Windows 11): full happy path against the dev backend with GreenMail.

## 7. Performance
- k6: 50 virtual users, list/read/send mix for 10 min on staging. Targets in NFR-PERF-001.
- Sync: 5,000-message folder initial sync against GreenMail completes in under 3 min, with memory under 256 MB per worker.

## 8. Entry and exit criteria
- **Entry** (per phase): requirements traced, test environment available.
- **Exit** (release): all M-requirement tests pass; all SEC tests for High or Critical risks pass or are formally waived; no open high or critical dependency advisories; DR-T01 executed; known issues documented.

## 9. Results log
Results are recorded in `docs/TEST_RESULTS.md` (command, date, commit, environment, pass/fail counts, failures).

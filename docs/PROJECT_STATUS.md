# MbunieMail — Project Status and Requirements Checklist

Last updated: 2026-10-14 (end of Phase 7, branch `phase-7-admin`). Legend: ✅ done and verified · 🟡 in progress or partially done · ⬜ not started · ⛔ blocked (see reason)

## Phase checklist
| Phase | Item | Status | Notes |
|---|---|---|---|
| 1 Audit | Environment and repository audit | ✅ | [ENVIRONMENT_AUDIT.md](ENVIRONMENT_AUDIT.md) |
| 2 Design | SRS | ✅ draft | Awaiting stakeholder review |
| 2 Design | SDD + ADRs | ✅ draft | ADR-001 depends on verifying that Hostinger allows VPS logins |
| 2 Design | Security risk assessment (STRIDE + 30 risks) | ✅ draft | Owners still to be named |
| 2 Design | Database design | ✅ draft | |
| 2 Design | API specification + openapi.yaml | ✅ draft | Redocly lint: 0 errors |
| 2 Design | Test plan + security test matrix | ✅ draft | |
| 2 Design | PDF versions of documents | ⬜ | Via headless Chrome once approved |
| 3 Foundation | Laravel 13.36 scaffold, config, `.env.example`, dev Docker deps | ✅ | `infra/docker-compose.dev.yml` |
| 3 Foundation | Migrations (all tables in DATABASE_DESIGN) | ✅ | Rollback verified |
| 3 Foundation | Auth: login, refresh rotation + reuse detection, logout, sessions, password reset, invitations | ✅ | |
| 3 Foundation | MFA (TOTP + recovery codes), policy enforcement, reauth | ✅ | Replay bug found and fixed |
| 3 Foundation | RBAC roles/permissions + gates, audit logger (hash chain + verify command) | ✅ | Mail-content policies arrive with Phase 5 resources |
| 3 Foundation | Security headers, problem+json errors, rate limiters, log redaction, trusted proxies | ✅ | |
| 3 Foundation | Quality gate `infra/scripts/check-backend.sh` (Pint, PHPStan L6, tests, log canary, composer audit, gitleaks) | ✅ | See TEST_RESULTS.md. CI pipeline once a remote exists |
| 4 Providers | MailProvider interface, provider-neutral errors, FakeProvider | ✅ | `app/Mailbox` |
| 4 Providers | CredentialVault (XChaCha20-Poly1305 envelope, KEK file, rotate and rewrap commands) | ✅ | |
| 4 Providers | ImapSmtpProvider (hardened TLS, SSRF host guard, IP pinning, send-outcome tracking) + GreenMail integration tests | ✅ | 8 integration tests over real TLS |
| 4 Providers | Live Hostinger verification | ⛔ | Needs authorized test mailboxes |
| 4 Providers | GmailProvider + OAuth (PKCE, CSRF-safe two-step completion, revoke) | ✅ (flag off) | Tested with HTTP fakes; real Google needs a Cloud project and verification |
| 4 Providers | Account API: link, credential update, unlink, diagnostics, folders | ✅ | |
| 5 Core mail | Folders (create), list (keyset), read (sanitised), flags, move, trash/permanent delete, spam, bulk, search, threads, attachments | ✅ | Folder rename and delete not yet |
| 5 Core mail | Outbox: idempotent send, at-most-once claim, unknown-outcome handling, Sent append, `\Answered`, drafts, uploads, rate limits, retention | ✅ | |
| 5 Core mail | Sync engine, scheduler, unique jobs, backoff with jitter, checkpoints, threading, contacts | ✅ | IMAP IDLE and CONDSTORE not used (later optimisation) |
| 5 Core mail | Image proxy (signed, SSRF-safe, redirect-checked) + email HTML sanitiser | ✅ | |
| 5 Core mail | Contacts autocomplete, preferences, sync status, notification payload privacy | ✅ | Push delivery (FCM) in Phase 6 |
| 6 Flutter | SDK installation | ⛔ | Flutter, Android SDK and JDK not installed. Approval needed |
| 6 Flutter | App shell, auth, inbox, reader, composer, settings | ⬜ | |
| 6 Flutter | Windows build | ⛔ | Requires a Windows build machine |
| 7 Admin | Admin panel: sign-in with mandatory TOTP and enrolment, reauth for writes, employees (invite, suspend, deactivate, roles, sessions, MFA reset, mailbox enable/disable), audit (filter, CSV export, chain verify), health, policies (MFA, attachments, domains), mail servers, failed jobs | ✅ | No mail-content access anywhere |
| 7 Admin | Break-glass request and approval workflow (FR-ADM-010, priority S) | ⬜ | Process-only for release 1; documented in INCIDENT_RESPONSE (Phase 9) |
| 7 Admin | Two-person approval for MFA reset | ⬜ | Deviation: a single admin with reauth plus audit for now; see risk R-24 note |
| 8 Security | Run full security matrix, SAST, dependency audit, secret scan | ⬜ | |
| 9 Deploy | ENVIRONMENT_VARIABLES.md | ✅ | |
| 9 Deploy | DEPLOYMENT, BACKUP, INCIDENT, HARDENING, RUNBOOK docs | ⬜ | Written alongside implementation |
| 10 Review | Final report | ⬜ | |

## Release blockers (from the risk assessment)
- ⬜ DKIM enabled and DMARC at `quarantine` or `reject` for `mt.co.tz` (R-25). Currently `p=none`.
- ⬜ MGT acceptance of residual phishing risk R-10.
- ⬜ Independent penetration test.
- ⬜ Legal and DPO review of PDPA 2022 obligations (R-30).
- ⬜ Restore drill DR-T01 executed.

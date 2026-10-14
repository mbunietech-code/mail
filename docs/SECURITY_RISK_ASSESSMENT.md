# MbunieMail — Security Risk Assessment

Version 0.1 · 2026-10-14 · Method: STRIDE threat model plus a quantitative risk register

> **Disclaimer:** MbunieMail is not, and will not be described as, "completely secure". This assessment covers foreseeable threats at design time. It must be re-run at each major release, and an **independent penetration test and code review** are required before production go-live.

---

## 1. Scoring method

**Risk score = Likelihood (1–5) × Impact (1–5)**

| Score | Likelihood | Impact |
|---|---|---|
| 1 | Rare (< once per 5 years) | Negligible: no data exposure, minutes of disruption |
| 2 | Unlikely | Minor: limited internal info, < 4 h disruption |
| 3 | Possible | Moderate: one mailbox exposed, or 1 day disruption |
| 4 | Likely | Major: several mailboxes or credentials exposed, regulatory notice |
| 5 | Almost certain (expected yearly) | Severe: company-wide mail compromise, executive mail leak, prolonged outage |

| Band | Score | Treatment rule |
|---|---|---|
| **Critical** | 20–25 | Must be mitigated before any release. No acceptance allowed |
| **High** | 12–19 | Must be mitigated to Medium or below before production. Acceptance needs CEO plus security owner sign-off |
| **Medium** | 6–11 | Mitigate where practical. The security owner may accept, with documented review in 6 months |
| **Low** | 1–5 | Accept and monitor |

Owners: **SO** Security Owner (to be named), **TL** Tech Lead, **OPS** Operations / sysadmin, **DPO** Data Protection Officer, **MGT** Management (CEO).

---

## 2. Assets
| Asset | Classification |
|---|---|
| Mailbox credentials (Hostinger passwords), Gmail OAuth tokens | **Secret** |
| `CREDENTIAL_KEK`, `APP_KEY`, DB and Redis passwords, TLS private keys | **Secret** |
| Email content and attachments (in transit or on demand) | **Confidential** (CEO mail: Highly confidential) |
| Metadata cache (subjects, snippets, correspondents) | **Confidential** |
| User accounts, password hashes, TOTP secrets, sessions | **Secret** |
| Audit log | **Confidential, integrity-critical** |
| Backups | Inherit the highest classification of their contents |

## 3. STRIDE summary by component
| Component | S | T | R | I | D | E |
|---|---|---|---|---|---|---|
| Flutter app | Stolen device tokens (R-03, R-14) | Tampered APK (R-20) | — | Local cache leakage (R-14), notifications (R-19) | — | Deep-link hijack (R-29) |
| API / Laravel | Credential stuffing (R-01) | Mass assignment (R-05) | Missing audit (R-24) | IDOR (R-06), verbose errors (R-17) | API flooding (R-15) | Broken authz (R-05) |
| Admin panel | Admin phishing (R-04) | CSRF (R-08) | Admin abuse (R-24) | Content access (R-24) | — | Role escalation (R-05) |
| Mail rendering | Spoofed sender (R-10) | — | — | Tracking pixels (R-09) | Decompression bombs (R-18) | XSS (R-08) |
| Provider adapters | — | Header / command injection (R-07) | — | Credential exposure (R-12) | Provider throttling (R-26) | SSRF (R-09) |
| Workers / queues | — | Queue poisoning (R-23) | — | Payload leakage (R-19) | Retry storms (R-23) | — |
| DB / backups | — | — | — | Dump leakage (R-22) | Restore failure (R-27) | — |
| Infra / DNS | Domain spoofing (R-25) | — | — | TLS misconfig (R-21) | Outage (R-26) | Unpatched OS (R-28) |

---

## 4. Risk register

Columns: **L** likelihood · **I** impact · **Inh** inherent score · **Res** residual score after controls.

### R-01 Account takeover via credential stuffing
- **Scenario:** An attacker replays leaked passwords from other breaches against `/auth/login` for `ceo@mt.co.tz`.
- **Assets:** user accounts, mailbox access · **Components:** API auth.
- **L4 I5 → Inh 20 Critical**
- **Preventive:** mandatory MFA for admins and policy-enforced for all; HIBP breached-password check; per-account and per-IP throttling (5 per minute per account+IP, progressive lockout of 15 min after 10 failures, no permanent lock to avoid DoS); generic errors.
- **Detective:** alert on more than 20 failures per 10 minutes across accounts or from one IP; audit `auth.login.failed`.
- **Recovery:** admin revokes sessions, forces password reset, reviews audit.
- **Tests:** SEC-T01 throttle test, SEC-T02 MFA-required test, SEC-T03 breached password rejected.
- **Res:** L2 I5 = **10 Medium** · Owner **SO**

### R-02 Weak passwords and insecure recovery
- **Scenario:** A user picks `MbuniTech2026`, or the reset link is guessable or long-lived.
- **L3 I4 → 12 High**
- **Preventive:** 12+ characters, context and breach checks; 256-bit reset tokens stored hashed, 30 min, single-use; reset revokes sessions; MFA still required after reset.
- **Detective:** audit resets; notify the user by email on password change.
- **Recovery:** admin-assisted reset with identity verification.
- **Tests:** SEC-T04 token reuse rejected, SEC-T05 token expiry, SEC-T06 sessions revoked after reset.
- **Res:** L1 I4 = **4 Low** · **SO**

### R-03 Session theft and token replay
- **Scenario:** Malware or a backup extracts a refresh token and the attacker replays it.
- **L3 I5 → 15 High**
- **Preventive:** 15-minute access tokens; rotating refresh tokens with family reuse detection; tokens only in Keystore or Credential Manager; `allowBackup=false`; TLS only.
- **Detective:** refresh reuse triggers family revocation, a `security.token_reuse` alert, and user notification.
- **Recovery:** user or admin revokes sessions; force re-login.
- **Tests:** SEC-T07 rotated-token reuse revokes family, SEC-T08 expired access token is rejected.
- **Res:** L2 I4 = **8 Medium** · **TL**

### R-04 Missing or bypassable MFA
- **Scenario:** An API route is reachable with an `mfa-pending` token, or MFA is skipped for admin web.
- **L3 I5 → 15 High**
- **Preventive:** `mfa-pending` tokens have a single ability (`mfa:verify`); global middleware checks the `mail` ability on all other routes; admin web requires MFA within the session; TOTP replay window enforced; MFA verify rate limit of 5 per minute.
- **Detective:** audit MFA failures; alert on more than 10 per hour per user.
- **Recovery:** revoke sessions; reset MFA via the admin-assisted flow (audited, requires second admin).
- **Tests:** SEC-T09 route-sweep test (every route rejects an `mfa-pending` token), SEC-T10 TOTP replay rejected.
- **Res:** L1 I5 = **5 Low** · **TL**

### R-05 Broken authentication or authorization (incl. privilege escalation, mass assignment)
- **Scenario:** An employee PATCHes `/users/me` with `role=super_admin`, or reaches an admin route.
- **L3 I5 → 15 High**
- **Preventive:** FormRequest validation with explicit `validated()` fields only; `$fillable` allow-lists; policies on every controller action; admin routes in a separate guard with permission middleware; no self-role change; super_admin required to grant admin.
- **Detective:** audit role changes; alert on any `authz.denied` from admin routes by non-admins.
- **Recovery:** revert role, revoke sessions, investigate.
- **Tests:** SEC-T11 route-permission matrix test (every route × every role), SEC-T12 mass-assignment test.
- **Res:** L1 I5 = **5 Low** · **TL**

### R-06 IDOR and cross-user data leakage
- **Scenario:** A user changes `/messages/{id}` to another user's message ULID, or a search spans other users' accounts.
- **L4 I5 → 20 Critical**
- **Preventive:** all queries scoped through `auth()->user()->emailAccounts()`; policies check ownership; ULIDs; organization_id scoping for future tenancy; no admin content bypass.
- **Detective:** log `authz.denied` with resource type; alert on more than 20 per hour per user.
- **Recovery:** revoke and investigate; DPO breach assessment.
- **Tests:** SEC-T13 cross-account test suite (every content endpoint with user B's token on user A's resources must return 404 or 403).
- **Res:** L1 I5 = **5 Low** · **TL**

### R-07 SQL, command, and IMAP/SMTP header injection
- **Scenario:** A search term containing `"` and CRLF injects IMAP commands, or a subject with `\r\nBcc:` injects headers.
- **L3 I4 → 12 High**
- **Preventive:** Eloquent and query builder with bound parameters only (no `DB::raw` with input); IMAP search via library criteria API with quoted literals and rejection of CR/LF/NUL; Symfony Mime header encoding (it rejects CRLF in headers); no `exec`/`shell_exec` on user input; ClamAV via socket, not shell.
- **Detective:** WAF or log patterns; validation failures logged.
- **Tests:** SEC-T14 CRLF in subject, recipient and search is rejected or encoded; SEC-T15 SQLi payload corpus on query params.
- **Res:** L1 I4 = **4 Low** · **TL**

### R-08 XSS, CSRF and malicious HTML email
- **Scenario:** An email contains `<img src=x onerror=…>`, CSS exfiltration, or `<form action=…>` phishing. A CSRF attack hits the admin panel.
- **L5 I4 → 20 Critical**
- **Preventive:** server-side allow-list sanitizer; client renders native widgets with no JS engine; admin panel CSP `default-src 'self'; script-src 'self' 'nonce-…'; frame-ancestors 'none'`; Blade auto-escaping; email content is **never** shown in the admin panel; CSRF tokens plus `SameSite=Strict` cookies; API is bearer-only (not cookie), so CSRF does not apply.
- **Detective:** sanitizer removal counters (telemetry without content).
- **Tests:** SEC-T16 XSS corpus (OWASP cheat sheet plus html5sec vectors) produces no script, handler or javascript URL in the output; SEC-T17 CSRF missing token returns 419.
- **Res:** L2 I3 = **6 Medium** · **TL**

### R-09 SSRF via remote images, previews or attachment processing
- **Scenario:** Email `<img src="http://169.254.169.254/latest/meta-data">` gets fetched by the backend proxy, or a provider-profile host is pointed at an internal IP.
- **L4 I4 → 16 High**
- **Preventive:** remote images off by default; HMAC-signed proxy URLs; DNS resolve-and-pin; deny RFC1918, loopback, link-local, CGNAT, ULA and multicast; no redirect to denied IPs; size and time caps; no URL previews feature; provider profiles admin-only and validated; egress firewall allow-list on the VPS (optional but recommended).
- **Tests:** SEC-T18 proxy denies a private, IPv6-mapped or redirecting URL; SEC-T19 unsigned or tampered signature is rejected.
- **Res:** L1 I4 = **4 Low** · **TL**

### R-10 Malicious attachments, phishing, spoofed senders
- **Scenario:** An invoice `.pdf.exe`, a macro document, or "ceo@mt.co" lookalike phishing.
- **L5 I4 → 20 Critical**
- **Preventive:** never execute attachments; download with `Content-Disposition: attachment` and a sanitized filename; magic-byte versus extension mismatch warning; dangerous-extension blocklist warning; optional ClamAV scan (fallback documented: warn "not scanned"); external-sender banner; display the actual address next to the display name; lookalike-domain warning (Levenshtein ≤ 2 of `mt.co.tz`); show SPF/DKIM/DMARC `Authentication-Results` when present.
- **Detective:** user "report phishing" (C); ClamAV detections logged.
- **Recovery:** incident response; password reset of affected users.
- **Tests:** SEC-T20 double-extension detection; SEC-T21 EICAR test file flagged (if ClamAV is enabled).
- **Res:** L4 I3 = **12 High (residual)**. Phishing cannot be eliminated by a client. **Acceptance requires MGT + SO sign-off**, conditional on staff awareness training and DMARC enforcement (R-25). · **SO**

### R-11 SMTP abuse, spam relaying, compromised account
- **Scenario:** A stolen MbunieMail session is used to send 5,000 phishing emails, getting the mt.co.tz domain blacklisted.
- **L3 I4 → 12 High**
- **Preventive:** per-user and per-account send rate limits (100 messages per hour, 500 recipients per hour); recipient cap; From must equal the authenticated mailbox (no arbitrary From); no open relay (the backend only submits authenticated per-mailbox).
- **Detective:** alert at 80 % of a limit; anomalous send volume.
- **Recovery:** suspend user; revoke; rotate mailbox password in hPanel.
- **Tests:** SEC-T22 rate limit enforced; SEC-T23 spoofed From is rejected.
- **Res:** L2 I3 = **6 Medium** · **SO**

### R-12 IMAP/SMTP credential exposure (server-side)
- **Scenario:** A DB dump or SQLi exposes stored mailbox passwords, or they show up in logs or exceptions.
- **L3 I5 → 15 High**
- **Preventive:** envelope encryption with the KEK outside the DB and `.env`; AAD binding; secrets wrapped in a `SensitiveParameter`/`Secret` value object that redacts in `__toString`/`__debugInfo`; log redaction processor; `APP_DEBUG=false`; DB user without FILE privilege; credentials decrypted only in worker memory per job.
- **Detective:** secret-scanner on logs (CI test greps logs from the test run); DB access audit.
- **Recovery:** rotate KEK; force mailbox password changes in hPanel; incident response.
- **Tests:** SEC-T24 credential row cannot be decrypted with a different account_id AAD; SEC-T25 no secret strings in logs after the full test suite.
- **Res:** L2 I5 = **10 Medium** · **SO**. *Inherent to ADR-001: documented, accepted subject to review.*

### R-13 Gmail OAuth token theft, excessive scopes, revocation
- **L2 I4 → 8 Medium** (feature-flagged off; becomes L3 when enabled → 12 High)
- **Preventive:** confidential server-side client; PKCE plus state; minimum scopes; tokens envelope-encrypted; revoke on disconnect; scope verification after token exchange; `invalid_grant` handling.
- **Tests:** SEC-T26 state replay or mismatch is rejected; SEC-T27 disconnect calls the revoke endpoint (mocked).
- **Res:** L1 I4 = **4 Low** · **TL**

### R-14 Insecure local token and credential storage
- **Scenario:** A rooted phone, or a Windows user profile copied by malware.
- **L3 I4 → 12 High**
- **Preventive:** Keystore and Credential Manager or DPAPI; SQLCipher cache keyed from secure storage; no mailbox passwords stored on the device ever (only API tokens); wipe on logout, remote revoke or 401 family-revoked; optional app lock (biometric/PIN, S); `FLAG_SECURE` option.
- **Tests:** SEC-T28 code review and grep: no `SharedPreferences` for tokens; SEC-T29 logout wipes the cache (widget/integration test).
- **Res:** L2 I3 = **6 Medium** · **TL**

### R-15 API rate-limit bypass and DoS
- **L3 I3 → 9 Medium**
- **Preventive:** Nginx `limit_req` per IP; Laravel per-user and per-route limiters; pagination caps; body size limits (Nginx `client_max_body_size 30m`); PHP-FPM and request timeouts; trusted-proxy config so `X-Forwarded-For` cannot be spoofed.
- **Tests:** SEC-T30 limiter returns 429 with Retry-After; SEC-T31 spoofed XFF does not reset the limit.
- **Res:** L2 I3 = **6 Medium** · **OPS**

### R-16 Brute-force login and OTP abuse
- **L4 I4 → 16 High**
- **Preventive:** see R-01; MFA limiter of 5 per minute and lockout after 10 per hour; recovery codes rate-limited; TOTP window ±1 step.
- **Tests:** SEC-T01, SEC-T32 OTP limiter.
- **Res:** L1 I4 = **4 Low** · **TL**

### R-17 Email enumeration and information disclosure
- **L4 I2 → 8 Medium**
- **Preventive:** identical responses and timing (dummy hash verify) for unknown users; generic reset response; problem+json without traces; `X-Powered-By` removed; `server_tokens off`.
- **Tests:** SEC-T33 unknown versus known user responses are identical (body and status); SEC-T34 production error contains no trace.
- **Res:** L2 I1 = **2 Low** · **TL**

### R-18 Insecure uploads and oversized attachments
- **Scenario:** A 2 GB upload, a zip bomb, `../../` filename, or polyglot file.
- **L3 I3 → 9 Medium**
- **Preventive:** size limits at Nginx, PHP and app; random storage names outside the web root; filename sanitization (basename, strip control characters, max 200); finfo plus magic bytes; no server-side decompression or rendering of attachments; temp files are deleted after send or after 1 h.
- **Tests:** SEC-T35 path traversal filename; SEC-T36 oversize returns 413; SEC-T37 MIME mismatch flagged.
- **Res:** L1 I3 = **3 Low** · **TL**

### R-19 Sensitive data in logs, analytics, crash reports, notifications
- **L4 I4 → 16 High**
- **Preventive:** redaction processor; no third-party analytics in release 1; crash reporting (if added) scrubbing bodies and headers; notification content minimized (FR-NOTIF-002); queue payload redaction; Sentry-style `before_send` scrubbing.
- **Tests:** SEC-T25 log grep; SEC-T38 notification payload contains no subject or body by default.
- **Res:** L2 I3 = **6 Medium** · **TL**

### R-20 Dependency vulnerabilities and supply chain
- **L4 I4 → 16 High**
- **Preventive:** lockfiles committed; `composer audit`, `npm audit`, `dart pub outdated` / OSV-scanner in CI; minimal dependencies; pinned GitHub Actions by SHA; signed releases (APK signing key, Windows code-signing certificate) with keys kept off developer laptops; Dependabot or Renovate.
- **Detective:** weekly scheduled audit.
- **Tests:** SEC-T39 CI fails on high or critical advisories.
- **Res:** L2 I4 = **8 Medium** · **TL**

### R-21 Misconfigured CORS, TLS, cookies, headers, reverse proxy
- **L3 I4 → 12 High**
- **Preventive:** CORS disabled for the API (native clients do not need it); admin same-origin only; TLS 1.2+ with Mozilla "intermediate" profile; HSTS (1 year, includeSubDomains after verification); `__Host-` cookies; headers (CSP, XFO DENY, nosniff, Referrer-Policy no-referrer, Permissions-Policy); `TrustProxies` limited to `127.0.0.1`.
- **Tests:** SEC-T40 header assertions in feature tests; SEC-T41 `testssl.sh` against staging (grade A).
- **Res:** L1 I4 = **4 Low** · **OPS**

### R-22 Database exposure, backup leakage, insufficient access control
- **L3 I5 → 15 High**
- **Preventive:** MariaDB bound to 127.0.0.1; firewall; separate least-privilege DB users; backups encrypted with `age` (public key on server, private key offline); KEK not included in DB backups; off-site bucket with restricted IAM and object lock; no dumps in VCS (`.gitignore`).
- **Tests:** SEC-T42 port scan from outside shows only 22/80/443; SEC-T43 backup file cannot be read without the private key.
- **Res:** L1 I5 = **5 Low** · **OPS**

### R-23 Queue poisoning, unsafe retries, duplicate delivery
- **L3 I4 → 12 High**
- **Preventive:** Redis requires AUTH and is localhost-only; jobs carry IDs only (no secrets), and state is reloaded from the DB; outbox state machine with atomic claim; `unknown` state never auto-retried; max tries; unique jobs.
- **Tests:** SEC-T44 concurrent workers claim the same outbox item exactly once; SEC-T45 timeout after DATA moves to `unknown` with no retry; SEC-T46 duplicate Idempotency-Key returns the same record.
- **Res:** L1 I3 = **3 Low** · **TL**

### R-24 Insider threat and administrator privilege abuse
- **Scenario:** An admin wants to read the CEO's email, or deletes audit logs to cover tracks.
- **L3 I5 → 15 High**
- **Preventive:** admin roles have no content permissions; no content endpoints in the admin panel; break-glass two-person rule (process-only); audit log append-only (DB user has no UPDATE/DELETE on `audit_logs`; hash-chain); daily export of audit to a write-once location; MFA plus reauth for admin writes; least privilege on the server (SSH key plus 2FA, sudo logging).
- **Detective:** audit chain verification job; alert on admin actions outside business hours (C).
- **Residual:** an OS-level root operator can technically read memory or decrypt. Mitigated by separation of duties and logging, but not eliminable.
- **Tests:** SEC-T47 admin token gets 403 on all content routes for others' resources; SEC-T48 hash-chain tamper detection.
- **Res:** L2 I5 = **10 Medium** · **MGT**

### R-25 DNS, SPF, DKIM, DMARC, domain impersonation
- **Observed 2026-10-14:** `mt.co.tz` SPF is `v=spf1 include:_spf.mail.hostinger.com ~all` (softfail). **DMARC is `p=none`** (monitoring only, no enforcement). DKIM was not found at the common Hostinger selectors (`hostingermail1-3`). It may use another selector and **must be verified in hPanel**.
- **Scenario:** An attacker sends mail as `ceo@mt.co.tz` to staff or partners (BEC fraud).
- **L4 I5 → 20 Critical**
- **Preventive:** enable DKIM in hPanel; add `rua` reporting to DMARC; move DMARC `p=none` → `quarantine` → `reject` over 4–8 weeks after verifying all legitimate senders; consider SPF `-all`; enable DNSSEC if `.co.tz` registrar supports it; registrar lock and MFA on the DNS account.
- **Detective:** DMARC aggregate reports.
- **Tests:** SEC-T49 DNS check script (SPF, DKIM, DMARC policy) in the release checklist.
- **Res:** after `p=reject` + DKIM: L2 I4 = **8 Medium** · **OPS / MGT**. **Release blocker: DKIM verified and DMARC at least `quarantine` before go-live, or MGT formally accepts.**

### R-26 Hostinger outages and provider rate limits
- **L4 I3 → 12 High**
- **Preventive:** per-mailbox connection caps; backoff; cached lists readable offline; status banner; limits documented after confirming with Hostinger.
- **Detective:** provider error-rate alert.
- **Recovery:** automatic resume from checkpoints.
- **Tests:** SEC-T50 / INT tests with fake provider returning throttling or outage errors.
- **Res:** L4 I2 = **8 Medium** (outages are outside MbunieMail's control) · **OPS**

### R-27 Backup restoration failure and data loss
- **L3 I4 → 12 High**
- **Preventive:** automated nightly backups; checksum; monthly restore drill to staging; KEK escrow (two custodians, sealed offline copy).
- **Tests:** DR-T01 documented restore drill with timing; DR-T02 decrypt mailbox credential after restore (proves the KEK backup works).
- **Res:** L1 I4 = **4 Low** · **OPS**

### R-28 Outdated libraries and operating systems
- **L4 I4 → 16 High**
- **Preventive:** Ubuntu LTS with `unattended-upgrades` (security); monthly patch window; PHP and Laravel on supported versions; Flutter stable; minimum Android API 26; EOL tracking in the OPERATIONS_RUNBOOK.
- **Tests:** SEC-T39; `lynis` audit on the VPS.
- **Res:** L2 I4 = **8 Medium** · **OPS**

### R-29 Deep-link and OAuth redirect abuse
- **Scenario:** A malicious Android app registers the `mbuniemail://` scheme to intercept the OAuth result, or an open redirect leaks the code.
- **L3 I4 → 12 High**
- **Preventive:** OAuth code is exchanged server-side and **never** delivered to the app; the deep link carries only `result=ok|error`; Android App Links (verified `https://` with `assetlinks.json`) preferred over a custom scheme; exact redirect URI registration; no open-redirect parameters; system browser rather than WebView for OAuth.
- **Tests:** SEC-T26; SEC-T51 callback rejects an unregistered redirect or unknown state.
- **Res:** L1 I3 = **3 Low** · **TL**

### R-30 Privacy, consent, retention, Tanzanian data protection
- **Context:** Tanzania Personal Data Protection Act 2022 (in force 2023) plus 2023 regulations: registration with the PDPC, lawful processing, data subject rights, security safeguards, breach notification, conditions for **cross-border transfer** (Hostinger and Google infrastructure are abroad).
- **L3 I4 → 12 High**
- **Preventive:** data minimization (ADR-002); retention schedule (SRS §10); employee privacy notice and acceptable-use policy; DPO appointment; records of processing; DPAs with Hostinger and Google reviewed; breach procedure in INCIDENT_RESPONSE.md.
- **Tests:** PRIV-T01 purge job deletes metadata within 24 h of unlink; PRIV-T02 retention job for audit logs.
- **Res:** L2 I3 = **6 Medium** · **DPO**. **Legal review required (not provided by this document).**

---

## 5. Summary heat map

| Risk | Inherent | Residual | Release blocker? |
|---|---|---|---|
| R-01 | 20 C | 10 M | Controls must be implemented |
| R-02 | 12 H | 4 L | — |
| R-03 | 15 H | 8 M | — |
| R-04 | 15 H | 5 L | — |
| R-05 | 15 H | 5 L | — |
| R-06 | 20 C | 5 L | SEC-T13 must pass |
| R-07 | 12 H | 4 L | — |
| R-08 | 20 C | 6 M | SEC-T16 must pass |
| R-09 | 16 H | 4 L | — |
| R-10 | 20 C | **12 H** | **MGT acceptance required** |
| R-11 | 12 H | 6 M | — |
| R-12 | 15 H | 10 M | — |
| R-13 | 8 M | 4 L | Only if Gmail enabled |
| R-14 | 12 H | 6 M | — |
| R-15 | 9 M | 6 M | — |
| R-16 | 16 H | 4 L | — |
| R-17 | 8 M | 2 L | — |
| R-18 | 9 M | 3 L | — |
| R-19 | 16 H | 6 M | — |
| R-20 | 16 H | 8 M | — |
| R-21 | 12 H | 4 L | — |
| R-22 | 15 H | 5 L | — |
| R-23 | 12 H | 3 L | — |
| R-24 | 15 H | 10 M | — |
| R-25 | 20 C | 8 M | **DKIM + DMARC ≥ quarantine** |
| R-26 | 12 H | 8 M | — |
| R-27 | 12 H | 4 L | DR-T01 must pass |
| R-28 | 16 H | 8 M | — |
| R-29 | 12 H | 3 L | — |
| R-30 | 12 H | 6 M | Legal review |

## 6. Required independent activities before go-live
1. External penetration test (API, admin, mobile, desktop).
2. Review of the crypto implementation (CredentialVault).
3. Legal and DPO review of PDPA 2022 obligations.
4. If Gmail is enabled: Google OAuth verification plus CASA assessment.

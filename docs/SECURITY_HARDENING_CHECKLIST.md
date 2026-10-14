# MbunieMail — Security Hardening and Release Checklist

Version 0.1 · 2026-10-14. Every item must be ✅ or carry a written, signed exception before the first production release (prompt §10, SRS §13 acceptance criteria). Status here reflects **this development environment**; production items are verified on the VPS.

Legend: ✅ verified · ⬜ to do · ⛔ blocked · 🟡 partially

## A. Application configuration
| # | Check | How to verify | Status |
|---|---|---|---|
| A1 | `APP_ENV=production`, `APP_DEBUG=false` | `deploy.sh` refuses otherwise; `curl` a 404 shows problem+json with no trace | ⬜ (enforced by script) |
| A2 | Unique `APP_KEY`, KEK created, both escrowed offline (two custodians) | Key register entry | ⬜ |
| A3 | `TRUSTED_PROXIES=127.0.0.1,::1` only | `.env`; HttpHardeningTest | ⬜ |
| A4 | Session cookie `__Host-` + Secure + HttpOnly + SameSite=Strict, 30-minute idle | Browser devtools on `/admin` | ⬜ |
| A5 | Dev-only variables **unset**: `MBUNIEMAIL_TEST_CA_FILE`, `MBUNIEMAIL_ALLOW_LOOPBACK_PROVIDERS`, `GREENMAIL_PASSWORD` | `grep` the `.env` (also ignored by code outside local/testing) | ⬜ |
| A6 | `GMAIL_ENABLED=false` unless Google verification is complete | `.env` | ⬜ |
| A7 | `config:cache`, `route:cache`, `view:cache` in place | `deploy.sh` | ⬜ |
| A8 | No `.env`, keys or dumps in the repository | gitleaks in `check-backend.sh` | ✅ (dev) |

## B. Accounts and access
| # | Check | Status |
|---|---|---|
| B1 | Every admin has MFA (dashboard warning shows 0) | ⬜ |
| B2 | At least two super administrators (no single point of failure; needed for P5) | ⬜ |
| B3 | MFA policy decided (all employees vs admins only) and set in `/admin/policies` | ⬜ |
| B4 | Session revocation verified on production: sign in on a phone, suspend in admin, next request returns 401 | ⬜ (automated test ✅) |
| B5 | Security alert email configured and a test alert received (`MBUNIEMAIL_ALERT_EMAIL`) | ⬜ |
| B6 | Named owners in the risk register: SO, DPO, OPS, MGT | ⬜ |

## C. Server and network
| # | Check | Status |
|---|---|---|
| C1 | Ubuntu LTS fully patched, `unattended-upgrades` on, NTP synchronised (TOTP depends on time) | ⬜ |
| C2 | UFW: only 22 (admin IPs), 80, 443 inbound; external `nmap` shows nothing else (SEC-T42) | ⬜ |
| C3 | SSH key-only, no root login | ⬜ |
| C4 | MariaDB and Redis bound to localhost; Redis password set; dangerous commands disabled | ⬜ |
| C5 | Least-privilege DB accounts applied (`db-grants.sh`); app account cannot UPDATE/DELETE `audit_logs` | ✅ verified on dev MariaDB 2026-10-14 / ⬜ prod |
| C6 | PHP-FPM pool: `expose_php` off, `display_errors` off, `zend.exception_ignore_args` on, dangerous functions disabled, `open_basedir` | ⬜ |
| C7 | Workers start and process sync and send under the systemd sandbox (exposure score 1.5 offline) | ⬜ staging |
| C8 | File permissions: `.env` 640 deploy:mbuniemail; KEK 600 mbuniemail; backups 700 | ⬜ |
| C9 | Log rotation installed; logs contain no secrets (canary test ✅ in CI) | ⬜ |

## D. TLS and HTTP
| # | Check | Status |
|---|---|---|
| D1 | Valid certificate, auto-renewal timer active, expiry alert (≥14 days) | ⬜ |
| D2 | `testssl.sh`: TLS 1.2/1.3 only, no weak ciphers, grade A (SEC-T41) | ⬜ |
| D3 | Headers: HSTS, CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy` (SEC-T40 ✅ in tests) | ⬜ prod |
| D4 | `nginx -t` passes with the shipped config | ✅ (dev, self-signed) |
| D5 | Body size 30 MB, request timeouts, rate limits active | ⬜ |
| D6 | No directory under `public/` shadows a route (PublicPathCollisionTest ✅) | ✅ |

## E. Mail domain (release blocker R-25)
Checked with `infra/scripts/check-dns.sh mt.co.tz` on 2026-10-14: **FAIL**.
| # | Check | Current | Action (hPanel → Emails → DNS / Email authentication) |
|---|---|---|---|
| E1 | DKIM published | Not found at common selectors | Enable DKIM in hPanel; re-run the check with the selector hPanel shows |
| E2 | SPF ends in `-all` | `~all` | After confirming every service that sends as `@mt.co.tz` is included, change to `-all` |
| E3 | DMARC enforcing | `p=none`, no `rua` | Week 0: `v=DMARC1; p=none; rua=mailto:dmarc@mt.co.tz; fo=1`. Review reports. Week 2–4: `p=quarantine; pct=25` → `100`. Then `p=reject` |
| E4 | MX at Hostinger | ✅ | — |

Release needs **E1 and E3 at quarantine or stricter**, or a formal MGT exception.

## F. Backups and recovery
| # | Check | Status |
|---|---|---|
| F1 | Nightly encrypted backup runs; off-site copy on immutable storage | ⬜ |
| F2 | `age` private key and KEK held offline by two custodians | ⬜ |
| F3 | Restore drill on staging within the 4 h RTO, including DR-T02 (credential decrypts) | ✅ dev drill (5 s) / ⬜ staging |

## G. Provider and integration
| # | Check | Status |
|---|---|---|
| G1 | Hostinger IMAP/SMTP settings confirmed in hPanel and entered in `/admin/providers` | ⬜ |
| G2 | Authorized live test with dedicated test mailboxes: login from the VPS IP, send/receive, Sent-copy behaviour | ⛔ awaiting test mailboxes |
| G3 | Hostinger sending limits known; MbunieMail limits set below them | ⬜ |
| G4 | If Gmail is enabled: Google verification + CASA, exact redirect URI, consent screen | ⬜ (flag off) |

## H. Assurance
| # | Check | Status |
|---|---|---|
| H1 | `infra/scripts/check-backend.sh` passes on the release commit | ✅ (dev, 230+ tests) |
| H2 | `composer audit`: no advisories | ✅ |
| H3 | Independent penetration test (API, admin, apps); high/critical findings fixed | ⬜ |
| H4 | All high/critical risks in SECURITY_RISK_ASSESSMENT are mitigated or formally accepted (R-10 phishing residual needs MGT sign-off) | ⬜ |
| H5 | Legal/DPO review of PDPA obligations; employee privacy notice issued | ⬜ |
| H6 | Flutter apps: release signing, secure storage verified on device, cert/permissions review | ⬜ Phase 6 |

**Sign-off** (all ✅ or an exception recorded): Security Owner ______ · DPO ______ · Management ______ · Date ______

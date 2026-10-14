# MbunieMail — Environment Audit (Phase 1)

Performed 2026-10-14 on the development host.

## Repository
- `/home/pro/Music/mail` was **empty**: greenfield, nothing overwritten.
- Git initialized (branch `main`), with a `.gitignore` that excludes `.env*`, keys, dumps, mail files and build output. No commits yet.

## Host
| Item | Found | Impact |
|---|---|---|
| OS | Kali GNU/Linux Rolling 2026.3, kernel 7.1.5, x86_64, 4 CPU, 7.7 GiB RAM (≈2 GiB free), 146 GB disk free | Fine for backend dev. Kali is not a production target (production uses Ubuntu LTS) |
| PHP | 8.4.24 CLI, with: openssl, sodium, intl, mbstring, pdo_mysql, curl, fileinfo, gd, zip, bcmath, pcntl, sockets | Good for Laravel 12/13. `sodium` enables envelope encryption |
| PHP missing | `imap`, `pdo_sqlite`, `redis` | Use pure-PHP `webklex/php-imap`. Tests use a MariaDB test DB (not SQLite). Use `predis/predis` or install `php-redis` |
| Composer | 2.10.3 | OK |
| MariaDB | 11.8.9 (service active) | OK. Needs `mbuniemail` and `mbuniemail_test` DBs and users |
| Redis | **Not installed** | Run via Docker for dev, or the `database` queue driver as fallback |
| Node / npm | 24.21.0 / 12.0.2 | For admin asset build (Vite) |
| Docker | 28.5.2, user in `docker` group | Runs Redis, GreenMail and Mailpit test containers |
| Flutter / Dart | **Not installed** | Must be installed (Flutter stable) before Phase 6 |
| Android SDK / Java | **Not installed** | Needed for Android builds (Android Studio or cmdline-tools + JDK 17) |
| Windows build host | **Not available** | Flutter Windows builds require Windows 10/11 with Visual Studio 2022 C++ workload |
| PDF tooling | No pandoc or wkhtmltopdf. `google-chrome` present | PDFs can be produced via headless Chrome print-to-PDF from rendered HTML |
| Port 8000 | In use by an existing container `mbunie-panel-api` (unrelated project) | MbunieMail dev server uses **8080**. That container is left untouched |

## External verification (no credentials used)
| Check | Result |
|---|---|
| `imap.hostinger.com:993` | Reachable. TLS 1.3. Certificate chain (Sectigo DV R36) and hostname verified |
| `smtp.hostinger.com:465` | Reachable. TLS 1.3. Chain and hostname verified |
| `smtp.hostinger.com:587` STARTTLS | Reachable. Certificate verified |
| `mt.co.tz` MX | `mx1.hostinger.com` (5), `mx2.hostinger.com` (10): Hostinger Email confirmed |
| SPF | `v=spf1 include:_spf.mail.hostinger.com ~all` (softfail) |
| DMARC | `v=DMARC1; p=none`: **monitoring only, not enforced** (risk R-25) |
| DKIM | Not found at selectors `hostingermail1/2/3`, `default`: **needs verification in hPanel** |

Settings still to confirm with the account owner: the exact IMAP/SMTP host and port shown in hPanel for this plan, the sending limits per mailbox per day, and whether SMTP submission auto-saves to Sent.

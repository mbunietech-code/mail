# MbunieMail

Corporate email client for MbunieTech: Flutter (Android + Windows) and a Laravel backend that connects to Hostinger IMAP/SMTP.
**Status:** pre-release; Phases 1–5 and 7 complete (Phase 6, the Flutter app, awaits SDK installation). See [docs/PROJECT_STATUS.md](docs/PROJECT_STATUS.md). Not production-ready.

## Layout
| Path | Contents |
|---|---|
| `backend/` | Laravel 13 API, admin panel (Phase 7), queue workers |
| `app/` | Flutter app (Phase 6) |
| `infra/` | Dev Docker Compose, scripts |
| `docs/` | SRS, SDD, security risk assessment, API spec, DB design, test plan and results |

## Local development (Linux; a Windows guide follows in docs/DEPLOYMENT.md)
Prerequisites: PHP 8.3+ (with `sodium`, `intl`, `mbstring`, `pdo_mysql`), Composer, Docker.

```bash
cp infra/.env.example infra/.env                      # then fill with: openssl rand -hex 24
infra/scripts/dev-tls.sh                              # throwaway CA + GreenMail cert; put infra/dev/tls/greenmail.p12.pass into DEV_GREENMAIL_KEYSTORE_PASSWORD
docker compose -f infra/docker-compose.dev.yml --env-file infra/.env up -d
cd backend && cp .env.example .env && composer install && php artisan key:generate
# set DB_PASSWORD / REDIS_PASSWORD in backend/.env to the infra/.env values
# set CREDENTIAL_KEK_PATH (outside backend/), MBUNIEMAIL_TEST_CA_FILE, GREENMAIL_PASSWORD; see docs/ENVIRONMENT_VARIABLES.md
php artisan mbuniemail:kek-rotate                     # creates the mailbox-credential key file (chmod 600)
php artisan migrate --seed
php artisan mbuniemail:create-admin admin@mt.co.tz "Admin Name"   # prompts for password
php artisan serve --port=8080                         # admin panel: http://localhost:8080/admin
php artisan queue:work --queue=send,sync,default      # background jobs (another terminal)
php artisan schedule:work                             # scheduler (another terminal)
```
Dev services: MariaDB `127.0.0.1:3307`, Redis `:6380`, Mailpit UI http://127.0.0.1:8025 (captures reset and invite emails), GreenMail fake IMAP/SMTP `:3143/:3993/:3025/:3465`.

## Quality gate
```bash
infra/scripts/check-backend.sh
```
This runs formatting, PHPStan level 6, the test suite (MariaDB `mbuniemail_test`), the log-leak canary scan, `composer audit` and gitleaks. Tests never touch real mailboxes or the network.

## Security
Never commit `.env` files, keys, database dumps or mail data (see `.gitignore`). Report security issues to the security owner (to be named) and see `docs/SECURITY_RISK_ASSESSMENT.md`.

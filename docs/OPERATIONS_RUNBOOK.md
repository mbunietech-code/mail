# MbunieMail — Operations Runbook

Version 0.1 · 2026-10-14 · Owner: OPS. Commands assume `cd /srv/mbuniemail/current/backend` and `sudo -u mbuniemail` for `artisan`.

## 1. Routine
| When | Task |
|---|---|
| Daily (automatic) | Encrypted backup 01:30; audit-chain verify 03:30; security alerts every 5 min; retention prune hourly |
| Daily (human, 5 min) | Read the alert email, if any. Glance at `/admin` health: failing checks, failed jobs, sync overdue, admins without MFA |
| Weekly | `/admin/jobs`: retry or discard failed jobs. Review `/admin/audit?action=admin.` for unexpected admin actions. Check accounts in `auth_failed` and contact their owners |
| Monthly | Patch window (`apt full-upgrade`, reboot if needed); restore drill on staging (BACKUP_AND_RESTORE.md §5); `composer audit` on the deployed release; check certificate expiry; review DMARC reports |
| Quarterly | Access review: who holds admin roles, deactivate leavers, rotate the DB/Redis/notification-mailbox passwords |
| Yearly | Rotate the credential KEK (§5); review the risk assessment, this runbook and the incident plan; consider a penetration test |

## 2. Service control
```bash
systemctl status 'mbuniemail-worker@*' mbuniemail-scheduler.timer mbuniemail-backup.timer nginx php8.4-fpm mariadb redis-server
journalctl -u mbuniemail-worker@sync -n 100 --no-pager        # worker output
tail -f /srv/mbuniemail/shared/storage/logs/laravel-$(date +%F).log
php artisan queue:restart                                      # graceful worker restart (after config changes)
php artisan mbuniemail:sync-due                                # queue due syncs now
php artisan mbuniemail:security-alerts --window=60             # evaluate alerts over the last hour
php artisan mbuniemail:audit-verify
php artisan mbuniemail:prune
```

## 3. Alerts and what to do
| Alert | Meaning | Action |
|---|---|---|
| `failed-logins` | ≥20 failed sign-ins in 10 min | INCIDENT_RESPONSE P3 |
| `refresh-reuse` | A rotated refresh token was replayed; that session family is already revoked | P2 |
| `admin-admin.user.mfa_reset` | An admin reset someone's MFA (single-admin action; this alert is the compensating control) | Confirm with the admin and the user that it was requested |
| `admin-admin.user.roles_changed` / `policy.changed` / `provider_profile.changed` | Privileged change | Confirm it was authorised; audit entries contain before/after values |
| `admin-security.kek.rotated` | Credential key rotation | Confirm it was planned; ensure `kek-rewrap` ran and the new key file is escrowed |
| `denied` | ≥20 denied actions | Look for a user probing other users' resources (`/admin/audit?result=denied`) |
| `send-unknown` | ≥5 sends with an unknown outcome | Provider instability; tell users to check Sent before resending |
| `failed-jobs` | ≥10 failed jobs in 10 min | `/admin/jobs`; logs |
| `mailbox-auth` | ≥5 mailboxes began failing auth | Hostinger password policy change, or an outage; check the provider |
| Audit chain broken (daily verify fails) | Tampering or corruption | P6, SEV-1 |

## 4. Common problems
**An employee's account shows `auth_failed`.** The mailbox password changed in Hostinger or expired. The employee updates it in the app (Accounts → Update password). Sync is paused until then by design.

**Outgoing message stuck as `unknown`.** The connection dropped after the message data was sent, so it may have been delivered. The employee checks Sent (or asks the recipient), then uses "Send again", which requires explicit confirmation. Never re-queue it from the database.

**Sync overdue for many accounts.**
1. `systemctl status mbuniemail-worker@sync` and `journalctl` for errors.
2. Redis reachable? Check the queue size on `/admin`.
3. Provider throttling? Accounts show `throttled` and back off automatically.

**Admin locked out (lost phone).** Another super admin uses "Reset MFA". If no other super admin exists, use the server console under the two-person rule. The command asks for confirmation, signs the user out everywhere, and writes an audit entry with the reason:
```bash
php artisan mbuniemail:reset-mfa admin@mt.co.tz --reason="INC-2026-014 lost phone, verified in person by <name>"
```
The admin re-enrols at the next sign-in. Also record it in the access register.

**Bootstrapping the first admin, or adding admins:** `php artisan mbuniemail:create-admin <email> "<Name>"` (the password is prompted). Then grant roles in `/admin`.

**Offboarding an employee.** `/admin/users/<user>` → Deactivate. That revokes all sessions, disables mailbox connections and stops sync. Data is deleted after the retention period. Separately, disable or reassign the Hostinger mailbox in hPanel.

## 5. Key rotation
**Credential KEK** (yearly, or immediately on suspected exposure):
```bash
php artisan mbuniemail:kek-rotate        # adds version N+1 and makes it current (old versions kept)
php artisan mbuniemail:kek-rewrap        # moves every credential to the new key; must report 0 failed
# escrow the updated key file (two custodians), then optionally remove old versions from the file
```
**APP_KEY:** add the old key to `APP_PREVIOUS_KEYS`, set a new `APP_KEY`, `config:cache`, `queue:restart`. Existing encrypted values stay readable. Escrow the new key.

**Database / Redis / mail passwords:** change at the source, update `.env` (or `/etc/mbuniemail/migrate.pass`, `~mbuniemail-backup/.my.cnf`), then `config:cache` and `queue:restart`.

## 6. Capacity signals
Add a worker instance (`mbuniemail-worker@sync` is safe to run in parallel; jobs are unique per account) when sync lag stays above 5 minutes. Move MariaDB to its own host above about 500 mailboxes. Metadata tables grow by roughly 1 KB per message cached; the 30-day window bounds them.

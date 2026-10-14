# MbunieMail — Backup and Restore

Version 0.1 · 2026-10-14 · Owner: OPS · Targets: **RPO 24 h, RTO 4 h** (SDD §11)

## 1. What is backed up, and what deliberately is not
| Item | Backed up? | How | Why |
|---|---|---|---|
| MariaDB database (users, roles, audit log, account settings, encrypted credentials, metadata cache, outbox) | ✅ nightly, plus before every deploy | `infra/scripts/backup.sh`: `mariadb-dump --single-transaction` piped through gzip into `age` (public-key encryption). Plaintext is never written to disk | Core state |
| `/etc/mbuniemail/keys/kek.json` (credential key-encryption key) | ⚠️ **separately**, never with the database | Two sealed offline copies (§3) | A stolen DB backup must not contain the key that decrypts the mailbox passwords inside it |
| `APP_KEY` and the rest of `.env` | ⚠️ separately, in the password manager or offline escrow | Manual, on change | APP_KEY decrypts TOTP secrets, subjects and snippets |
| `age` private key (backup decryption) | ⚠️ offline only (two custodians) | Paper or hardware token in two locations | Backups on the server can be written but never read there |
| Mail bodies and attachments | ❌ not needed | — | They live at Hostinger/Google; the cache can be rebuilt by sync |
| Uploaded outbox attachments (`storage/app/private/outbox`) | ❌ | — | Transient (deleted within 24 h of sending) |
| Logs | ❌ (rotated, 90 days) | — | Audit log is in the DB |

## 2. Schedule and retention
- Nightly at 01:30 UTC (`mbuniemail-backup.timer`, ±15 min jitter) and before every deploy (`deploy.sh`).
- Local copies: `/var/backups/mbuniemail`, 30 days. Off-site copies: 30 daily plus 12 monthly, on a bucket with **object lock / immutability** and credentials that can write but not delete. That protects against ransomware and a compromised server.
- Each file has a `.sha256` next to it. The script refuses suspiciously small output and anything that is not `age`-encrypted.

## 3. One-time key setup (security owner + one other custodian)
```bash
# On an offline/trusted workstation, NOT the server:
age-keygen -o mbuniemail-backup.key            # private key → print or store offline twice, then wipe
grep 'public key' mbuniemail-backup.key         # age1… → AGE_RECIPIENT in /etc/mbuniemail/backup.env
```
After `mbuniemail:kek-rotate` on the server, copy `/etc/mbuniemail/keys/kek.json` to two sealed offline locations, the same way. **Record in the key register:** date, KEK version, custodians, storage locations. Repeat after every KEK rotation.

`/etc/mbuniemail/backup.env` (owner root, mode 0640, group `mbuniemail-backup`):
```
DB_NAME=mbuniemail
BACKUP_DIR=/var/backups/mbuniemail
AGE_RECIPIENT=age1...
RETENTION_DAYS=30
OFFSITE_CMD='rclone copy "$1" offsite:mbuniemail-backups/'
```

## 4. Restore procedure
1. **Declare** the incident or drill and note the start time (RTO clock).
2. Provision the host per DEPLOYMENT.md §3–7, or use the existing one if only the data is lost.
3. Fetch the chosen backup and its `.sha256` from off-site storage. Bring the `age` private key and the KEK from offline storage (both custodians present).
4. Restore into a **new** database (the script refuses to overwrite an existing one):
   ```bash
   infra/scripts/restore.sh mbuniemail-20261014T013000Z.sql.gz.age mbuniemail_restored ./mbuniemail-backup.key
   ```
5. Put the KEK at `/etc/mbuniemail/keys/kek.json` (0600, owner `mbuniemail`). Put `.env` back, with `DB_DATABASE` set to the restored DB (or rename it).
6. Verify:
   - `php artisan mbuniemail:audit-verify`;
   - sign in to `/admin`;
   - check that one mailbox account syncs, which proves the credential decrypts (DR-T02);
   - check the counts of users, accounts and audit rows against expectations.
7. Re-apply the grants (`db-grants.sh`), start the workers and timer, and note the end time.
8. **Shred** the private key file from the restore workstation (`shred -u`). Record the drill or incident in the log (§6).

If the **KEK is lost**: restore still works, but every stored mailbox credential is unrecoverable. Set all accounts to `auth_failed`; users re-enter their mailbox passwords (Gmail users reconnect). This is why the KEK escrow exists.

## 5. Restore drills
- **Monthly** on staging: run §4 with the latest off-site backup, timed, and record the result.
- **Locally / CI:** `infra/scripts/dr-drill.sh` (dev containers) runs the real `backup.sh` and `restore.sh` end to end.

## 6. Drill log
| Date | Environment | Backup | Result | Restore time | Notes |
|---|---|---|---|---|---|
| 2026-10-14 | Dev containers (`dr-drill.sh`) | Seeded scratch DB, 36 tables | ✅ pass | 5 s | Checksum verified. Row counts match. Audit chain intact. **DR-T02:** restored credential decrypts with the KEK. No plaintext password and no KEK in the dump. Wrong key, tampered file and overwrite of an existing DB all refused |
| _first staging drill_ | | | | | Required before go-live (release checklist) |

# MbunieMail — Incident Response Plan

Version 0.1 · 2026-10-14 · Owner: Security Owner (SO), to be named · Review: every 12 months and after every major incident

> This plan is a starting point. It must be adopted by MbuniTech management, the named roles filled in, and the legal/PDPA steps confirmed by counsel. It is not legal advice.

## 1. Roles
| Role | Who | Responsibility |
|---|---|---|
| Incident lead | Security Owner (SO) | Declares severity, coordinates, decides containment |
| Technical lead | Tech Lead / OPS | Investigation, containment, recovery on servers |
| Communications | Management (CEO delegate) | Staff, partners and client messages |
| DPO | Data Protection Officer | Personal-data assessment, PDPC notification, data-subject notices |
| Scribe | Anyone not hands-on | Timeline, in UTC |

Keep an offline contact list (phone numbers) for these roles; email may be compromised.

## 2. Severity
| Level | Examples | Response |
|---|---|---|
| **SEV-1** | Credential KEK or APP_KEY exposed; database or backup leaked; admin account takeover; audit chain broken; mass mailbox compromise | Immediately, all hands; management informed within 1 h |
| **SEV-2** | Single employee account takeover; refresh-token reuse with signs of attacker activity; phishing campaign with successful clicks; sustained credential stuffing | Within 4 h |
| **SEV-3** | Provider outage (Hostinger/Google); single failed-job storm; isolated suspicious login | Next business day |

## 3. General procedure
1. **Detect.** Sources: security alert email (`mbuniemail:security-alerts`), audit log, user report, provider notice.
2. **Triage.** Assign severity, open a timeline (UTC), and preserve evidence: export the audit log (`/admin/audit` CSV), copy relevant logs, and **do not** wipe servers before evidence is copied.
3. **Contain** using the playbooks below.
4. **Eradicate and recover.** Patch, rotate, restore (BACKUP_AND_RESTORE.md).
5. **Notify** (§5).
6. **Review** within 10 working days: root cause, what went well, actions with owners; update the risk register.

## 4. Playbooks

### P1 — Employee account takeover (SEV-2)
1. `/admin/users/<user>` → **Suspend** (revokes every API session and admin session immediately) → **Sign out everywhere**.
2. If MFA was bypassed or the phone was lost → **Reset MFA**.
3. The employee changes the **mailbox password in Hostinger hPanel** (the attacker may have read it in another channel), then re-enters it in MbunieMail.
4. Review the audit log for that user: logins (IP prefixes), `account.*`, `mail.send.*`. Check Sent items with the employee for unauthorised mail.
5. Re-activate once clean. Consider DPO assessment if mail content was exposed.

### P2 — Refresh-token reuse alert (SEV-2)
The family is already revoked automatically. Contact the user: was a device lost or shared? Check for malware on the device. If repeated, treat as P1.

### P3 — Credential stuffing / brute force (SEV-2)
Application lockouts and Nginx limits are already active. Identify the source networks from the audit log. If sustained, block them in UFW (`ufw deny from <cidr>`). Confirm no successful logins from those ranges. Remind staff to use unique passwords.

### P4 — KEK or server compromise (SEV-1)
1. Isolate the server (firewall to admin IPs only). Snapshot the disk for forensics.
2. Assume the mailbox credentials are exposed. **Coordinate password resets for every linked Hostinger mailbox in hPanel**, and revoke Gmail access (Google account security page).
3. Build a clean server (DEPLOYMENT.md). Generate a new APP_KEY and a **new KEK** (`mbuniemail:kek-rotate` on a fresh key file; old credentials cannot be trusted, so users re-enter them). Rotate DB, Redis, SMTP notification and Google client secrets.
4. Restore the database from a backup taken **before** the compromise.
5. Revoke all sessions on the new DB (as the migrator account, in this order because of the foreign key): `DELETE FROM personal_access_tokens; DELETE FROM refresh_sessions; DELETE FROM sessions;`. Everyone signs in again.
6. DPO: notification assessment (§5).

### P5 — Administrator abuse or admin account takeover (SEV-1)
1. A second super administrator suspends the account. If no other super admin is available: `php artisan tinker` on the server, under the two-person rule, logged.
2. Export the audit log for `admin.*` actions by that actor, then reverse unauthorised role, policy and provider changes (each change records before/after values).
3. Verify the audit chain (`mbuniemail:audit-verify`). If it is broken, treat as P6.

### P6 — Audit chain broken (SEV-1)
The audit log was altered or truncated outside the application (the app DB user cannot UPDATE/DELETE it). Preserve the DB as-is. Compare with the last off-site backup and the daily verification results to find the window. Investigate DB-level access (root, maint user, backups).

### P7 — Phishing campaign against staff (SEV-2)
1. Ask staff, via a channel the attacker does not control, not to click and to report.
2. Hostinger: block the sender in hPanel spam filters. Identify recipients (with their consent, or via the provider's admin tools; **not** via MbunieMail, which gives admins no content access).
3. Anyone who entered credentials → P1.
4. Check DMARC status (`check-dns.sh`). Spoofing of `@mt.co.tz` means DMARC enforcement is still missing (R-25).

### P8 — Hostinger or Google outage (SEV-3)
The apps show "provider unreachable" and keep cached lists. Sync backs off automatically. Check the provider status pages. If sends end `unknown`, tell users to check Sent before resending. No data action is needed.

### P9 — Lost or stolen device (SEV-2/3)
The user, or an admin, revokes that device's session (`Sessions` in the app or `/admin/users/<user>`). The app wipes local data on its next contact (Phase 6).

## 5. Notification (DPO)
- **Tanzania PDPA 2022 / PDPC:** where a breach of personal data is likely to harm data subjects, notify the Personal Data Protection Commission and affected individuals within the timeframe set by the Act and regulations. **Confirm the exact obligations and deadlines with counsel and record them here before go-live.**
- **Hostinger / Google:** report abuse involving their services.
- **Clients and partners:** if mail they sent or received may have been exposed, as decided by management.
- Keep a breach register entry for every SEV-1/SEV-2, even if notification is not required.

## 6. Exceptional mailbox access (break-glass), release 1
MbunieMail gives administrators **no** mailbox-content access. If lawful access to an employee's mail is required (legal hold, investigation, or death or incapacity):
1. A written request with justification and legal basis goes to the DPO.
2. **Two approvers**: DPO plus a member of management, neither of whom is the requester.
3. Access is performed in **Hostinger hPanel** under the provider's own audit, never through MbunieMail. It is time-boxed (≤ 24 h) and the scope is minimised.
4. The employee is informed unless legally prohibited. Everything is recorded in the breach/access register.

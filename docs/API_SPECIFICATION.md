# MbunieMail — API Specification

Version 0.1 · 2026-10-14 · Base URL `https://<API_HOST>/api/v1` · Machine-readable spec: [openapi.yaml](openapi.yaml)

## 1. Conventions
- **Transport:** HTTPS only (HSTS). Plain HTTP is redirected to HTTPS at Nginx and never served by the API.
- **Format:** JSON (`Content-Type: application/json`), except attachment upload (`multipart/form-data`) and download (binary stream).
- **Auth levels**
  - `public`: no token. Rate limited by IP.
  - `mfa-pending`: access token with ability `mfa:verify` only.
  - `user`: access token with ability `mail` (MFA completed or not required).
  - `user+reauth`: `user`, plus a password or TOTP confirmation in the last 10 minutes (`POST /auth/reauth`).
  - Admin operations are served by the **admin web panel** (session plus CSRF), not by this bearer API. See §12.
- **Object authorization:** every `{id}` is resolved only within the caller's own resources. Unknown or foreign IDs both return **404** (no existence oracle).
- **IDs:** ULID strings.
- **Pagination:** `?limit=` (default 50, max 100) and `?cursor=` (opaque). Responses: `{ "data": [...], "next_cursor": "..."|null }`.
- **Idempotency:** `Idempotency-Key` header (8–80 chars, `[A-Za-z0-9_-]`). **Required** on `POST /outbox/send`, optional on other POSTs. Replays within 24 h return the original response.
- **Request ID:** the server returns `X-Request-Id`. The client may send one (ULID) for correlation.
- **Timeouts:** server request timeout 30 s (body fetch 20 s, provider side).

### 1.1 Error format (RFC 9457)
```json
{
  "type": "https://docs.mbuniemail/errors/validation",
  "title": "The given data was invalid.",
  "status": 422,
  "code": "VALIDATION_FAILED",
  "request_id": "01JABCDXYZ...",
  "errors": { "to.0": ["The to.0 must be a valid email address."] }
}
```

| HTTP | code | Meaning |
|---|---|---|
| 400 | `BAD_REQUEST` | Malformed JSON or headers |
| 401 | `UNAUTHENTICATED` | Missing, expired or revoked token |
| 401 | `MFA_REQUIRED` | Token is `mfa-pending` |
| 403 | `FORBIDDEN` | Authenticated but not permitted |
| 403 | `REAUTH_REQUIRED` | Sensitive action needs `POST /auth/reauth` |
| 403 | `MFA_ENROLLMENT_REQUIRED` | Policy requires MFA enrollment first |
| 404 | `NOT_FOUND` | Not found **or not yours** |
| 409 | `CONFLICT` | State conflict (for example the outbox item is already sending) |
| 409 | `IDEMPOTENCY_MISMATCH` | Same key, different body |
| 413 | `PAYLOAD_TOO_LARGE` | Upload exceeds limit |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | |
| 422 | `VALIDATION_FAILED` | Field errors in `errors` |
| 422 | `PROVIDER_AUTH_FAILED` | Mailbox rejected credentials |
| 422 | `PROVIDER_NOT_SUPPORTED` | Capability unsupported by provider |
| 423 | `ACCOUNT_LOCKED` | Temporary lockout. `Retry-After` |
| 429 | `RATE_LIMITED` | `Retry-After` |
| 502 | `PROVIDER_UNREACHABLE` | IMAP/SMTP/Google down |
| 503 | `PROVIDER_THROTTLED` | Provider throttling. `Retry-After` |
| 504 | `PROVIDER_TIMEOUT` | |
| 500 | `INTERNAL_ERROR` | Generic. No details |

### 1.2 Rate limits (defaults, configurable)
| Limiter | Limit | Key |
|---|---|---|
| `login` | 5 per minute, plus lockout 15 min after 10 failures per hour | email + IP, and IP |
| `mfa` | 5 per minute, 10 per hour | user |
| `password-reset` | 3 per hour | email, plus 10 per hour per IP |
| `api` | 120 per minute | user |
| `sync-refresh` | 6 per minute | account |
| `send` | 100 messages per hour, 500 recipients per hour | user, and account |
| `upload` | 30 per minute | user |
| `image-proxy` | 300 per minute | user |

### 1.3 Audit
Endpoints marked 🅰 write an `audit_logs` entry (action name shown). Audit context never includes passwords, tokens, codes, subjects or bodies.

---

## 2. Authentication

> **Implemented in Phase 3:** everything in this section except device push tokens. Notes: login and refresh responses include `session_id` (the refresh-token family id, also used by `/auth/sessions/{id}`). `POST /auth/mfa/confirm` returns `tokens` when it completes a policy-forced enrolment. `/auth/mfa/enroll` and `/confirm` accept either an enrolment token or a full token with a fresh reauth.

### POST /auth/login · public · 🅰 `auth.login.success|failed`
Request:
```json
{ "email": "developer@mt.co.tz", "password": "••••••••", "device": { "id": "01J…", "platform": "android", "name": "Pixel 8" } }
```
Validation: `email` required, valid, max 254 characters. `password` required, max 256. `device.platform` must be one of `android` or `windows`.
200 (MFA not enabled and not required):
```json
{ "status": "authenticated", "access_token": "…", "access_expires_in": 900, "refresh_token": "…", "refresh_expires_in": 2592000, "user": { "id": "01J…", "name": "Developer", "email": "developer@mt.co.tz", "mfa_enabled": false } }
```
200 (MFA required): `{ "status": "mfa_required", "mfa_token": "…", "expires_in": 300 }`
200 (enrollment required by policy): `{ "status": "mfa_enrollment_required", "mfa_token": "…" }`
Errors: 401 `INVALID_CREDENTIALS` (generic, same for an unknown user), 423, 429.

### POST /auth/mfa/verify · mfa-pending · 🅰 `auth.mfa.success|failed`
`{ "code": "123456" }` or `{ "recovery_code": "xxxx-xxxx-xx" }`, giving 200 with the same body as authenticated login. Errors: 401 `INVALID_MFA_CODE`, 429.

### POST /auth/refresh · public (refresh token in body) · 🅰 on reuse `security.refresh_reuse`
`{ "refresh_token": "…" }` returns 200 with a new access and refresh pair (rotation). Reuse of a rotated token returns 401 and revokes the family.

### POST /auth/logout · user · 🅰 `auth.logout`
Revokes the current session. 204.

### POST /auth/reauth · user
`{ "password": "…" }` or `{ "code": "123456" }`. 204. Sets a 10-minute reauth window on the session.

### POST /auth/password/forgot · public · 🅰
`{ "email": "…" }` always returns 202 `{ "status": "if_exists_email_sent" }`.

### POST /auth/password/reset · public · 🅰 `auth.password.reset`
`{ "email", "token", "password", "password_confirmation" }` returns 204. All sessions are revoked.

### POST /auth/invitations/accept · public · 🅰 `auth.invitation.accepted`
`{ "token", "name", "password", "password_confirmation" }` returns 200 with `mfa_enrollment_required` if policy applies.

### MFA management
| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | /auth/mfa/enroll | user+reauth **or** mfa-pending (enrollment) | Returns `{ otpauth_uri, secret }` once. Not yet active |
| POST | /auth/mfa/confirm | same | `{ code }` activates and returns `{ recovery_codes: [10] }` once · 🅰 `auth.mfa.enabled` |
| DELETE | /auth/mfa | user+reauth | Blocked if policy requires MFA · 🅰 |
| POST | /auth/mfa/recovery-codes | user+reauth | Regenerates and invalidates old codes · 🅰 |

### Sessions
| Method | Path | Auth | |
|---|---|---|---|
| GET | /auth/sessions | user | List `{ id, device_name, platform, ip_prefix, last_seen_at, current }` |
| DELETE | /auth/sessions/{id} | user | 🅰 `auth.session.revoked` |
| DELETE | /auth/sessions | user+reauth | Revoke all except current · 🅰 |

### Profile
| GET | /me | user | `{ id, name, email, roles, mfa_enabled, preferences }` |
|---|---|---|---|
| PATCH | /me | user | `{ name }` only. Other fields are ignored or rejected |
| PUT | /me/password | user+reauth | `{ current_password, password, password_confirmation }`. Revokes other sessions · 🅰 |

---

## 3. Mail accounts

> **Implemented in Phase 4:** list, link (`POST`), show, `PATCH` (display name only; signatures arrive with the Phase 5 sanitizer), credential replace, unlink, diagnostics and folders (`GET /accounts/{id}/folders`), plus Gmail `authorize` and the new **`POST /accounts/gmail/complete`** `{ "completion": "<64 chars>" }` (same session that called authorize; returns 201 with the account). Link and credential-replace attempts are limited to 5 per user per 10 minutes. Unknown or foreign account ids return 404.
| Method | Path | Auth | Description |
|---|---|---|---|
| GET | /accounts | user | List own accounts with status |
| POST | /accounts | user+reauth | Link an IMAP/SMTP mailbox · 🅰 `account.linked` |
| GET | /accounts/{id} | user | Detail plus capabilities |
| PATCH | /accounts/{id} | user | `{ display_name, signature_html }` (signature sanitized) |
| PUT | /accounts/{id}/credential | user+reauth | Replace password (re-verified) · 🅰 `account.credential.updated` |
| DELETE | /accounts/{id} | user+reauth | Unlink and purge · 🅰 `account.unlinked` |
| GET | /accounts/{id}/diagnostics | user | `{ status, last_synced_at, last_error_code, remedy, folders:[{name, last_uid, last_sync}], capabilities }` |
| POST | /accounts/{id}/sync | user | Trigger sync now · limiter `sync-refresh` · 202 |
| POST | /accounts/gmail/authorize | user+reauth | Feature flag `GMAIL_ENABLED`. Returns `{ authorization_url }` |

**POST /accounts** request:
```json
{ "email_address": "developer@mt.co.tz", "password": "••••", "display_name": "Developer" }
```
Validation: the domain must be in the org's `allowed_domains`. The provider profile is chosen by domain mapping (the client cannot supply hosts). The password is at most 256 characters.
201: `{ "id": "01J…", "email_address": "developer@mt.co.tz", "status": "syncing", "provider": "hostinger" }`
Errors: 422 `PROVIDER_AUTH_FAILED`, 422 `DOMAIN_NOT_ALLOWED`, 409 `ACCOUNT_ALREADY_LINKED`, 502, 504.

---

## 4. Folders
| Method | Path | Auth | |
|---|---|---|---|
| GET | /accounts/{id}/folders | user | `{ data: [{ id, name, role, unread_count, total_count, selectable }] }` |
| POST | /accounts/{id}/folders | user | `{ name }` (1–100 characters, no control characters or delimiter abuse) |
| PATCH | /folders/{id} | user | Rename (custom only) |
| DELETE | /folders/{id} | user | Custom and empty only. 409 otherwise |

## 5. Messages
| Method | Path | Auth | |
|---|---|---|---|
| GET | /folders/{id}/messages | user | List. Query: `limit, cursor, sort=date_desc|date_asc|from, unread=1, starred=1` |
| GET | /messages/{id} | user | Full message (body fetched on demand, sanitized) |
| PATCH | /messages/{id} | user | `{ is_seen?, is_flagged? }` |
| POST | /messages/{id}/move | user | `{ folder_id }` (must be in the same account) |
| DELETE | /messages/{id} | user | Move to Trash. `?permanent=1` only allowed from Trash |
| POST | /messages/bulk | user | `{ ids: [≤100], action: "seen"|"unseen"|"flag"|"unflag"|"move"|"trash", folder_id? }` |
| POST | /messages/{id}/spam | user | `{ spam: true|false }` |
| GET | /threads/{thread_key} | user | `?account_id=` messages in thread, chronological |

**Message list item**
```json
{ "id": "01J…", "thread_key": "01J…", "from": { "name": "CEO", "email": "ceo@mt.co.tz" },
  "subject": "Q4 plan", "snippet": "Please review…", "date": "2026-10-14T08:12:00Z",
  "is_seen": false, "is_flagged": true, "has_attachments": true, "is_external": false }
```

**GET /messages/{id}** response:
```json
{ "id": "01J…", "headers": { "from": {…}, "to": [..], "cc": [..], "reply_to": [..], "date": "…", "message_id": "<…>" },
  "subject": "…",
  "body": { "html": "<p>sanitized…</p>", "text": "…", "remote_images_blocked": 3, "remote_images_allowed": false },
  "attachments": [{ "id": "01J…", "filename": "plan.pdf", "mime_type": "application/pdf", "size": 120334, "is_inline": false, "warning": null }],
  "security": { "external_sender": false, "lookalike_domain": false, "spf": "pass", "dkim": "pass", "dmarc": "pass" } }
```
Query `?images=allow` returns HTML with remote images rewritten to signed proxy URLs (an explicit consent action).

## 6. Search
`GET /accounts/{id}/search?q=&from=&to=&subject=&since=&before=&has_attachment=&unread=&folder_id=&limit=&cursor=` · user
Validation: each text field is ≤ 200 characters, with CR, LF and NUL rejected. Dates in ISO-8601 format. At least one criterion is required. `q` means body or full text (provider IMAP SEARCH TEXT). Others map to criteria. Limiter `api` plus 20 per minute for full-text searches.

## 7. Attachments
| Method | Path | Auth | |
|---|---|---|---|
| GET | /attachments/{id} | user | Streams from the provider. `Content-Disposition: attachment; filename*=UTF-8''…`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox` |
| POST | /outbox/attachments | user | multipart `file` (≤ 25 MB). Returns `{ id, filename, size, mime_type, scan_status, warning }` |
| DELETE | /outbox/attachments/{id} | user | |

Upload validation: size; filename sanitized; MIME by magic bytes; extension–MIME mismatch flagged; blocked-extension policy (`.exe .scr .bat .cmd .js .vbs .ps1 .msi .jar .lnk .hta .iso`), returning 422 `ATTACHMENT_BLOCKED` when policy is `block`.

## 8. Outbox (compose and send)
| Method | Path | Auth | |
|---|---|---|---|
| POST | /outbox/send | user, **Idempotency-Key required** · 🅰 `mail.send.queued` (no content) | Queue send. 202 |
| GET | /outbox/{id} | user | Status `{ status: queued|sending|sent|failed|unknown, last_error_code }` |
| POST | /outbox/{id}/resend | user | Only from `failed` or `unknown`, with an explicit user action |
| PUT | /drafts/{id} | user | Upsert a draft (client-generated ULID) |
| GET | /drafts | user | |
| DELETE | /drafts/{id} | user | |

**POST /outbox/send**
```json
{ "account_id": "01J…", "to": ["a@x.com"], "cc": [], "bcc": [], "subject": "Hello",
  "html": "<p>Hi</p>", "attachment_ids": ["01J…"],
  "reply_to_message_id": "01J…", "mode": "new|reply|reply_all|forward", "include_original_attachments": false }
```
Validation: total recipients 1–100, each RFC 5322 valid and ≤ 254 characters; subject ≤ 998 characters, CR/LF stripped; html ≤ 2 MB (sanitized); attachments owned by the caller and total ≤ 25 MB; `From` is always the account address (no override).
202: `{ "id": "01J…", "status": "queued", "message_id": "<01J…@mt.co.tz>" }`

## 9. Contacts
`GET /contacts/suggest?q=` (≥ 2 characters, ≤ 10 results, own data plus active company directory) · `GET/POST/PATCH/DELETE /contacts…` (C).

## 10. Notifications and devices
| Method | Path | Auth | |
|---|---|---|---|
| PUT | /devices/{id}/push-token | user | `{ token }` FCM token (encrypted at rest) |
| DELETE | /devices/{id}/push-token | user | |
| GET/PUT | /notification-preferences | user | Per account and folder |
| GET | /sync/status | user | Cheap polling endpoint: `{ accounts: [{ id, status, unread_inbox, last_synced_at }], changed_since_token }` |

## 11. Settings, image proxy, health
| Method | Path | Auth | |
|---|---|---|---|
| GET/PATCH | /me/preferences | user | theme, density, remote_images, show_subject_in_notifications |
| GET | /image-proxy?u=&sig=&exp= | user | Signed URL only. See SDD §8.5 |
| GET | /up | public | Liveness, `200 OK`, no details |
| GET | /health | user with `health.read` | `{ db, redis, queue_lag_s, failed_jobs }` |

## 12. OAuth callback
`GET /oauth/google/callback?code=&state=` · public (state-bound) · 🅰 `account.gmail.linked|failed`
Validates the state (hash lookup, unexpired, unconsumed), exchanges the code with the PKCE verifier, and checks the granted scopes. Responds with a `302` to `<APP_LINK_URL>/oauth/google#result=pending&completion=<one-time code>` on success, or `#result=error&code=<ERROR_CODE>` (`INVALID_STATE`, `ACCESS_DENIED`, `SCOPE_NOT_GRANTED`, `TOKEN_EXCHANGE_FAILED`, `PROVIDER_UNREACHABLE`). It never carries tokens or the authorisation code, and uses the URL fragment so values stay out of logs. The redirect URI must match `GOOGLE_REDIRECT_URI` exactly.

## 13. Administration (web panel, `/admin`, session + CSRF + MFA)
Not part of the bearer API. All writes need reauth (10 min) and are audited 🅰.

| Route | Permission | Action / audit |
|---|---|---|
| GET /admin/users | users.manage | list, filter |
| POST /admin/invitations | users.manage | `admin.user.invited` |
| POST /admin/users/{id}/suspend · /activate · /deactivate | users.manage | `admin.user.status_changed` |
| PUT /admin/users/{id}/roles | users.manage (admin roles: admins.manage) | `admin.user.roles_changed`. Cannot modify self |
| POST /admin/users/{id}/sessions/revoke | users.manage | `admin.user.sessions_revoked` |
| POST /admin/users/{id}/mfa/reset | users.manage + second admin approval | `admin.user.mfa_reset` |
| GET /admin/users/{id}/accounts | users.manage | account list with status only, **no content** |
| POST /admin/accounts/{id}/disable | users.manage | `admin.account.disabled` |
| GET/PUT /admin/policies | policies.manage | MFA policy, allowed domains, limits, attachment policy, retention · `admin.policy.changed` |
| GET/PUT /admin/provider-profiles | policies.manage | `admin.provider_profile.changed` |
| GET /admin/audit (+ CSV export) | audit.read | export is audited `admin.audit.exported` |
| GET /admin/health | health.read | queues, failed jobs, sync lag |
| GET/POST /admin/jobs/failed/{id}/retry | jobs.manage | redacted payload view |
| /admin/break-glass/* | breakglass.approve | request, approve or decline. Two-person rule |

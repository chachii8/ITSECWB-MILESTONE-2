# Audit logging (rubric alignment)

## Authentication events

**Logged:** Successful logins (customer `login.php`, admin/staff `login-admin.php`), MFA completion, failed attempts (invalid credentials, unknown account, lockout, blocked account, customer on admin URL), MFA enable, lockout escalation. **Logout** is recorded as action `LOGOUT` (see `logout.php`) with user id and role before the session is destroyed.

## Authentication attempts and time

Each event is stored with a **timestamp** (`audit_log.created_at` in the database; datetime prefix on each line in `logs/audit.log`). **Scope:** login endpoints and explicit `log_audit()` calls—the application does **not** write an audit row for every page view or every in-page authorization check (redirect-only failures are not logged as separate events).

## Read / data access

**Not logged:** Routine reads (browsing products, viewing profiles, opening admin lists, loading images) are **not** written to the audit log. Only actions that call `log_audit()` are recorded (see `audit_log.php` and call sites).

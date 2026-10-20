# Pasta – invite-only PHP pastebin on Turso

Requirements: PHP 8.1+ with `curl` and `mbstring`. No composer packages.

## Setup
1. Create a database and token via cli or https://turso.tech/:
   ```
   turso db create pasta
   turso db show pasta --url          # libsql://pasta-<org>.turso.io
   turso db tokens create pasta
   ```
2. Export env vars for PHP (php-fpm `env[...]`, Apache `SetEnv`, or shell for CLI):
   ```
   TURSO_URL=libsql://pasta-<org>.turso.io
   TURSO_TOKEN=...
   ```
3. Create tables + first admin (admins can mint invites):
   ```
   php setup.php [username]
   ```
5. Serve ONLY `public/` as the web root (keeps config/db/lib out of reach):
   ```
   php -S localhost:8080 -t public
   ```
7. Log in, open "Invites", generate a code, send the link to a friend.

## Notes
- Sessions are HttpOnly + SameSite=Lax (+ Secure on HTTPS). Serve over HTTPS in production.
- Invites are single-use and claimed atomically; they can expire or be revoked.
- Private pastes are visible only to the owner/admins; unlisted ones to anyone with the link.
- Expired pastes are deleted lazily when requested.
- Not included: login rate limiting beyond a small delay (add fail2ban / a reverse-proxy limit),
  password reset, and cleanup of expired pastes (cron: DELETE FROM pastes WHERE expires_at < strftime('%s','now')).

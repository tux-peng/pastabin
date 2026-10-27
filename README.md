# Pasta – invite-only PHP pastebin

Stores pastes in Turso/libSQL, SQLite, or MySQL/MariaDB.

Requirements: PHP 8.1+ with `mbstring`, plus one of: `curl` (Turso), `pdo_sqlite` (SQLite),
`pdo_mysql` (MySQL/MariaDB). No composer packages.

## Setup
1. Serve ONLY `public/` as the web root (keeps config/db/lib out of reach):
   php -S localhost:8080 -t public
2. Open the site. You are sent to the installer (`install.php`), which lets you pick the database,
   tests the connection, creates the tables and the first admin, and writes `config.local.php`.
   The installer locks itself afterwards; delete `config.local.php` to run it again.
   - Turso: `turso db create pasta`, `turso db show pasta --url`, `turso db tokens create pasta`
     (or use https://turso.tech/).
   - SQLite: the web server needs write access to the database file and its directory
     (default `data/pasta.sqlite`).
   - MySQL/MariaDB: create an empty database and a user with rights on it first.
   If the web server cannot write the project directory, the installer shows the config to save by hand.
3. Log in, open "Invites", generate a code, send the link to a friend.

Run the installer right after deploying: until it has been completed, anyone who can reach the site can run it.

### Configuring without the web installer
Set environment variables (php-fpm `env[...]`, Apache `SetEnv`, or shell for CLI); they also
override `config.local.php`:
- `DB_DRIVER` = `turso` | `sqlite` | `mysql` (defaults to `turso` when only `TURSO_URL` is set)
- Turso: `TURSO_URL`, `TURSO_TOKEN`
- SQLite: `SQLITE_PATH`
- MySQL: `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_DB`, `MYSQL_USER`, `MYSQL_PASS`

Then create the tables + first admin: `php setup.php [username]`

## Notes
- Sessions are HttpOnly + SameSite=Lax (+ Secure on HTTPS). Serve over HTTPS in production.
- Invites are single-use and claimed atomically; they can expire or be revoked.
- Private pastes are visible only to the owner/admins; unlisted ones to anyone with the link.
- Owners can edit their pastes (content, title, language, visibility, expiry, folder) and sort them
  into personal folders. Deleting a folder keeps its pastes (they become unfiled). Admins can delete
  any paste but only edit their own.
- Databases created by older versions are upgraded automatically on the next request
  (or run `php setup.php`).
- Expired pastes are deleted lazily when requested.
- Not included: login rate limiting beyond a small delay (add fail2ban / a reverse-proxy limit),
  password reset, and cleanup of expired pastes
  (cron: `DELETE FROM pastes WHERE expires_at < <unix time now>`).

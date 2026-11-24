# MemberFlow — Operation, backups and deployment

This guide covers running MemberFlow locally on XAMPP, keeping the data safe, creating accounts, and what a real HTTPS deployment needs. It contains no passwords or keys; enter those only in `.env` yourself.

## 1. Local operation (XAMPP, Windows)

### Starting
1. Open the **XAMPP Control Panel** and **Start MySQL**. Apache is only needed if you want phpMyAdmin.
2. Open a terminal in the project folder:
   ```
   cd C:\xampp\htdocs\laravelproject_Sarzuelo
   php artisan config:clear
   php artisan serve                       # http://127.0.0.1:8000
   ```
3. Email processing:
   - **Classroom / demo:** set `QUEUE_CONNECTION=sync` in `.env`. Each email is sent inside the request, right after the database commit, so no worker is needed. A failed send is recorded as **Failed** in the Email log, the action itself still succeeds, and you can **Retry** from the Email log.
   - **Production:** set `QUEUE_CONNECTION=database` and run exactly one `php artisan queue:work --tries=3` (see §6). This adds automatic retries (30 s and 120 s back-off) and keeps pages fast.
4. After any `.env` change: run `php artisan config:clear`, and restart `serve` (and `queue:work` if used).

### Stopping MariaDB safely
- Use **Stop** in the XAMPP Control Panel, or `C:\xampp\mysql\bin\mysqladmin.exe -u root shutdown`.
- **Never** end `mysqld.exe` from Task Manager or with `taskkill`. A forced stop can leave the InnoDB redo log inconsistent and corrupt the data files. It is also why the database was moved to `C:\mysql_new\data`: see the `datadir` setting in `C:\xampp\mysql\bin\my.ini`.
- Before shutting Windows down, stop MySQL first.

## 2. Configuration you set yourself (`.env`)

| Setting | Notes |
|---|---|
| `APP_KEY` | Created by `php artisan key:generate`. Changing it invalidates every signed link, invitation email job and session |
| `APP_URL` | Address used in email links. On one PC: `http://127.0.0.1:8000`. For a phone on the same Wi-Fi: `http://<PC IPv4>:8000` (see `lan-demo.md`) |
| `APP_DEBUG` | `false` whenever anyone else can reach the app (debug pages reveal internals) |
| `MAIL_*` | Gmail SMTP with a 16-character App Password, or `MAIL_MAILER=log` for no delivery. See `email-and-security.md` |
| `GOOGLE_MAPS_KEY` | Browser key. Restrict it in Google Cloud to your site addresses (for example `http://127.0.0.1:8000/*`, `http://localhost:8000/*`, your LAN address, and your HTTPS domain later) and to the **Maps JavaScript API** only |
| `OPENWEATHER_KEY` | Server key. It is never sent to browsers |

Never paste keys or passwords into chats, documents, tickets or commands. `.env` and `.env.backup*` are git-ignored.

## 3. Backups and restore

### Make a backup (read-only for the database)
```
C:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction --routines --triggers --events --hex-blob ^
  --default-character-set=utf8mb4 memberflow --result-file=C:\memberflow_backups\memberflow_YYYYMMDD_HHMM.sql
certutil -hashfile C:\memberflow_backups\memberflow_YYYYMMDD_HHMM.sql SHA256
```
- Leave out `--databases`. Without it the file has no `CREATE DATABASE` / `USE memberflow` lines, so it can never overwrite the live database by accident when you restore it elsewhere.
- Keep backups outside the project folder, and keep the SHA-256 value with them.

### Check a backup (recommended after every backup)
```
mysql -u root -e "CREATE DATABASE memberflow_restore_check CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root memberflow_restore_check < C:\memberflow_backups\memberflow_YYYYMMDD_HHMM.sql
mysql -u root -e "CHECKSUM TABLE memberflow.members, memberflow_restore_check.members;"
mysql -u root -e "DROP DATABASE memberflow_restore_check;"
```
Compare the row counts and checksums of the important tables (`members`, `membership_transactions`, `payments`, `users`, `tasks`).

### Restore after a loss (only when you really need to)
1. Stop `php artisan serve` and any queue worker.
2. Restore into a **new** database first and check it, as above.
3. Only then either point `DB_DATABASE` in `.env` at the restored database, or, after a fresh backup of the damaged one, drop and recreate `memberflow` and load the file into it.
4. Run `php artisan config:clear` and `php artisan migrate:status`; every migration should show **Ran**.

Never use `migrate:fresh`, `migrate:refresh`, `db:wipe` or seeders to "fix" the live database: they erase or alter data. MemberFlow refuses those commands against any database other than `memberflow_testing`.

### Code backup
Copy the project folder without `vendor`, `node_modules`, `storage/logs` and **any** `.env*` file (secrets). For example:
```
robocopy C:\xampp\htdocs\laravelproject_Sarzuelo C:\memberflow_backups\code_YYYYMMDD /E ^
  /XD vendor node_modules storage\logs /XF .env .env.testing .env.backup*
```

## 4. Accounts

| Need | How |
|---|---|
| First administrator | `php artisan migrate --seed` creates it from `MEMBERFLOW_ADMIN_EMAIL` / `MEMBERFLOW_ADMIN_PASSWORD`. It refuses if an administrator already exists |
| Another administrator | `php artisan memberflow:create-admin --name="Evaluator Admin" --email=evaluator@example.org`. You type the password twice at a hidden prompt (8+ characters, upper- and lower-case letters and a number). It is never printed or logged, and an audit event is recorded. No web page creates administrators |
| Change an existing administrator's sign-in email and password | `php artisan memberflow:change-admin-credentials --user=<id> --current-email=<current> --email=<new>`. Run it first with `--dry-run`, which only checks and shows what would change. The real run asks for the new password twice at hidden prompts, then emails an 8-digit code to the **new** address only (valid 15 minutes, 3 attempts, single use). Email, password, verification time, "remember me" token, the audit entries ("Sign-in email changed", "Password changed") and the sign-out of every session of that administrator are applied together or not at all. The id, role, active status and all history stay as they are. Needs `MAIL_MAILER=smtp` and `SESSION_DRIVER=database`. If the run is interrupted, nothing changes; wait 20 minutes before running it again |
| A member login (walk-in member) | Members → the member → **Login account → Send activation invitation**. The member opens the one-time link (valid 72 h) and chooses a password. Opening the link also proves that they own the email address |
| A member login (online applicant) | Automatic: right after the applicant verifies their email, MemberFlow emails them an activation link |
| Deactivate / reactivate a login | On the member's page (Login account) or under Users. History is kept; a deactivated account cannot sign in and an open session ends on the next click |
| Change a member's email | Edit the member. Pending activation links stop working. An existing login keeps its old sign-in email until the member confirms the new address from that inbox **while signed in** |

**Professor or evaluator access:**
1. Create a dedicated administrator with `memberflow:create-admin`, or move the existing administrator to an evaluation address with `memberflow:change-admin-credentials`. Either way, use an address you control.
2. Register a fictional member whose email is a Gmail "+" alias of a test inbox you control (for example `yourtest+evaluator@gmail.com`).
3. Send that member an activation invitation and activate it yourself, so the evaluator can also see the member portal.
4. Hand over the two sign-in details privately. Never write them in documents or commit them.
5. After the evaluation, deactivate both accounts from Users. They are kept for history.

## 5. Same-Wi-Fi demonstration (phone)

See `lan-demo.md`. In short: set `APP_URL=http://<PC IPv4>:8000`, run `php artisan serve --host=0.0.0.0 --port=8000`, allow inbound TCP 8000 for the local subnet only, and use fictional data. The Google Maps key must allow that address. "Use my current location" needs HTTPS, so on a phone over plain HTTP members pick the point on the map or type it.

## 6. Production deployment (HTTPS), checklist

MemberFlow is a school project and has **not** been deployed publicly. A real deployment needs at least:

1. **HTTPS** with a valid certificate, on a real web server (Apache or Nginx with PHP-FPM 8.2), with the document root set to `public/`.
2. **Environment settings:**
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain`;
   - `SESSION_SECURE_COOKIE=true`; consider `SESSION_ENCRYPT=true`;
   - run `php artisan config:cache`, `route:cache` and `view:cache` after each deploy.
3. **Database:** a dedicated MySQL/MariaDB user with a strong password and rights only on `memberflow` (not `root`). Automated daily backups, with regular restore checks (§3).
4. **Queue:** `QUEUE_CONNECTION=database`, with one `php artisan queue:work --tries=3` supervised by Supervisor (Linux) or NSSM (Windows), restarted after each deploy (`php artisan queue:restart`).
5. **Scheduler:** a cron entry `* * * * * php /path/artisan schedule:run`. It runs `memberflow:expire` (memberships, 00:05) and `memberflow:expire-invitations` (00:10).
6. **Email:** a sending domain with SPF/DKIM (or a transactional email provider) rather than a personal Gmail account.
7. **Access logs:** exclude or redact `/invitations/*`, `/apply/verify/*`, `/apply/status/*` and `/account/email/confirm/*` (see `email-and-security.md` §5).
8. **Google Maps key:** restrict it to the HTTPS domain only, and replace `DEMO_MAP_ID` with your own Map ID (`GOOGLE_MAPS_MAP_ID`).
9. **Headers:** add HSTS and a full Content-Security-Policy allowing the Google Maps origins.
10. **Never** run `migrate:fresh`, `db:wipe` or the `DemoDataSeeder` in production. Deploy schema changes with `php artisan migrate --force` after a verified backup.

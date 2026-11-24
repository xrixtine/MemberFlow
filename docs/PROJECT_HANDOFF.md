# MemberFlow — Technical handoff

**Written:** November 24, 2026, after revision R8, the administrator credential change and an unfinished InfinityFree audit. **Updated** the same day after the initial GitHub commit and the privacy/documentation cleanup.
**Readers:** a developer or Claude session continuing this project with no earlier context. Read this file first, then the documents in §20.

> **This file contains no secrets.** It has no passwords, API keys, `APP_KEY`, SMTP credentials, tokens, `.env` values or private database records. The administrator's sign-in email is deliberately left out. Ask the project owner for any of these; never ask them to paste one into a chat.

---

## 0. Status at a glance

| Area | Status |
|---|---|
| Graded core: members, categories, levels, transactions, payments, auth, dashboard, printouts | **Complete**, tested and demonstrated locally |
| Roles: exactly two that can sign in (Administrator, Member) | **Complete** (revision R0–R8) |
| Member accounts: activation invitations, member portal, member tasks | **Complete** |
| Gmail SMTP | **Verified live** in Phase 7b (test email confirmed in the inbox). Member activation and email-change emails were tested only with fake mail and log mode |
| Google Maps and OpenWeather | **Verified live** (R7, R8) on `127.0.0.1` and on the owner's LAN address |
| Automated tests | **632 passed, 4,410 assertions** (last full run Nov 24, 2026, on `memberflow_testing`) |
| Administrator credential change (`memberflow:change-admin-credentials`) | **Done and verified.** The owner ran it against the main database and confirmed the new sign-in works. The read-only post-check passed (§14) |
| InfinityFree hosting | **Prepared, not deployed.** Both required code changes are implemented and tested (§16). The step-by-step procedure is in `deployment.md` §7. Deploying needs the owner's hosting account and approval |
| GitHub | **Public repository** `github.com/xrixtine/MemberFlow`, branch `main`. On Nov 24, 2026 the history was rewritten (owner-approved force-push) so every commit uses the owner's GitHub no-reply address. Screenshots are included after their privacy review (§19) |
| Screenshots | **Retaken Nov 24, 2026** from the final Administrator–Member system on the test database with fictional data (22 images). Privacy-reviewed. Originals kept outside the repository |

---

## 1. Project overview and academic requirements

**MemberFlow: Membership Management and Transaction Processing System with Role-Based Task Management** is a school Laravel project.

- The authoritative requirements are in `files and instructions/Laravel Project Instructions.pdf`:
  - pages 1–2 list the requirements;
  - page 3 is the 100-point rubric.
- The other three PDFs in that folder are the professor's tutorials (setup, models/MySQL, email/maps/weather).

Required by the instructions, all implemented:
- member CRUD with deactivate and search by name, category and level;
- categories: Student, Regular, Senior Citizen, Corporate;
- levels: Basic, Silver, Gold, Platinum, each with a fee, validity and benefits;
- membership applications and renewals;
- payments with official reference numbers;
- statuses Pending, Paid, Approved, Expired and Cancelled;
- transaction history per member;
- MySQL through migrations and Eloquent;
- models and controllers for Member, MembershipCategory, MembershipLevel, MembershipTransaction and Payment;
- validation (required fields, email format, duplicate membership number, payment amount);
- Laravel built-in authentication;
- a dashboard showing totals, active members, pending transactions and collected payments;
- a printable member record and receipt;
- **only Paid transactions may be Approved**;
- payments always linked to a valid transaction.

Rubric mapping and evidence: `docs/rubric-matrix.md`. It is a self-assessment, not a guaranteed grade.

## 2. Architecture and technology stack

| Layer | Choice |
|---|---|
| Framework | **Laravel 12** (PHP 8.2 rules out Laravel 13). `composer.json` requires `php ^8.2`, `laravel/framework ^12.0`, `laravel/tinker` |
| PHP | XAMPP **PHP 8.2.12** (pdo_mysql, mbstring, openssl, fileinfo, curl, intl, bcmath) |
| Database | XAMPP **MariaDB 10.4.32**. Main database `memberflow`; test database `memberflow_testing` |
| Front end | Blade, **Tailwind CSS v4** via **Vite 6** (`@tailwindcss/vite`), plain JS modules (`resources/js/app.js`, `location.js`, `weather.js`). Charcoal/lime design tokens in `resources/css/app.css` |
| Tests | PHPUnit (class style), Laravel Pint for code style |
| Extra packages | **None** added beyond Laravel's defaults |

**Code organisation:** thin controllers, a service layer, Form Requests, policies, enums, events and listeners, and console commands.

| Folder | Contents |
|---|---|
| `app/Services` | `MembershipService` (register, application, renewal, approve, cancel, expire); `PaymentService` (row locks, integer cents, idempotency, voids); `InvitationService`; `MemberAccountService`; `MemberPortalService`; `TaskService`; `UserAccountService`; `PublicApplicationService`; `ApplicantVerificationService`; `EmailDeliveryTracker`; `ReportService`; `WeatherService`; `StaffRetirementService`; `AdminCredentialService`; `SecureLinkRedactor` |
| `app/Support` | `Money` (string to integer cents, never floats), `NumberGenerator` (MF-/TXN-/OR- numbers), `DatabaseSafety` (test and destructive-command guards), `AppUrl`, `FormTimer`, `OneTimeCode` |
| `app/Enums` | `UserRole`, `MemberStatus`, `MemberSource`, `TransactionType`, `TransactionStatus` (transition map), `PaymentMethod`, `TaskStatus`, `TaskPriority`, `InvitationStatus`, `EmailDeliveryStatus` |
| `app/Http/Middleware` | `EnsureUserIsActive` (`active`), `EnsureUserHasRole` (`role:admin` / `role:member`), `NoReferrer`, `RejectHoneypot`, `SecurityHeaders` |
| `app/Policies` | `TaskPolicy`, `MembershipTransactionPolicy`, `PaymentPolicy`, `UserPolicy`, `UserInvitationPolicy` |
| Routes | **96 routes:** 65 administrator routes (`auth` + `active` + `role:admin`, `/admin` prefix); 11 member routes (`role:member`); the rest are guest/public or shared authenticated routes (login, profile, weather, invitation acceptance, public application) |

Full architecture: `docs/MemberFlow-Documentation.md` §4–§10.

## 3. Development history

### Original phases (plan "Revision 3"; all complete)

| Phase | Content |
|---|---|
| 1 Environment | Node, XAMPP MariaDB, databases `memberflow` and `memberflow_testing` created |
| 2 Foundation | Laravel 12 project, Tailwind theme, `.env` / `.env.testing` / `phpunit.xml` forced to the test database, test guard and destructive-command guard, enums, `Money`, `NumberGenerator`, migrations with CHECK constraints, seeders, login/logout/active/role middleware, layouts, error pages, profile |
| 3 Members, categories, levels | CRUD, search and filters, deactivate and soft-delete guards, printable record |
| 4 Transactions | `MembershipService`, transition map, renewal rules (60-day window), approve/cancel/expire, history |
| 5 Payments | Locking, integer cents, idempotency key, voids before approval, receipts |
| 6 Tasks | Assignment history, activity timeline, archive and soft delete (never hard delete) |
| 7a Invitations | Hashed single-use tokens (originally for staff) |
| 7b Public application and email | `/apply`, signed verification and status links, rate limits, honeypot, delivery tracking (`email_deliveries`), Email log, test email. **Gmail SMTP verified live** |
| 7c Maps and weather | Map picker with consent, server-side OpenWeather proxy with caching and graceful failure |
| 8 Dashboards and reports | Live figures, reports, responsive polish |
| 9 Testing and docs | 9a full suite; 9b LAN demonstration (the owner runs it; the developer never added a firewall rule); 9c `DemoDataSeeder`, documentation, screenshots |

### Database incident and recovery (Nov 23, 2026, before R0)

- A forced stop of MariaDB (Task Manager-style) left the InnoDB redo log of the original data directory inconsistent.
- A read-only forensic investigation followed. Salvage used `innodb_force_recovery=6` **on copies only**, with logical exports and SHA-256 sums. Folders include `C:\mysql_recovery`, `C:\mysql_export_backup_20261123` and several `C:\mysql_*` lab folders.
- A **clean data directory `C:\mysql_new\data`** was initialised, and the databases were reloaded from verified exports.
- `C:\xampp\mysql\bin\my.ini` now points `datadir` there and sets `bind-address=127.0.0.1`. `innodb_force_recovery` is not active. Earlier versions are kept as `my.ini.backup_20261123_193142`, `my.ini.before-clean-switch` and `my.ini.before-innodb-fix`.
- **Lesson:** stop MariaDB only with the XAMPP Control Panel or `mysqladmin -u root shutdown`.
- The same MariaDB server also holds **other, unrelated projects' databases. Never touch them.**

### Revision R0–R8: from Admin + Staff to Administrator + Member

Every schema change and data migration on the main database ran only after explicit owner approval, a fresh verified backup, a rehearsal on a restored copy, and a dry run.

| Revision | What was done |
|---|---|
| R0 | Code and database backups, verification, baseline |
| R1 | Migration `2026_11_24_100000_add_member_accounts_to_users_and_invitations` (`users.member_id`, unique, role/link CHECK constraints). **Applied to main** after approval |
| R2 | Member authentication, authorization, routes, account management, dashboard foundation |
| R3 | Member activation flows (walk-in invitation and automatic invitation after applicant verification), email-change confirmation, migration `2026_11_25_100000_allow_system_issued_member_invitations` (`invited_by` nullable for automatic invitations). **Applied to main** |
| R4 | Task eligibility: only active members with a current membership can receive and update tasks |
| R5 | Member portal: dashboard, membership, payments, receipts, read-only tasks for expired or pending members |
| R6 | `memberflow:retire-staff` (dry run by default). **Executed on main, limited to two users:** one former staff user became a member account linked to an existing member record; the other became `legacy_staff`. History was preserved |
| R7 | Live Google Maps and OpenWeather verification (picker interactions 22/22, weather 12/12) |
| R8 | Final integration: E2E 48/48, security review, responsive and accessibility pass (43 pages × 3 widths), documentation rewrite, integrity audit 32/32 |
| After R8 | `memberflow:change-admin-credentials` added (48 new tests) and run by the owner (§14) |

## 4. Final role architecture

- `users.role` values: `admin`, `member`, plus historical `staff` / `legacy_staff`.
  - `UserRole::canSignIn()` is true **only** for admin and member.
  - `worksOnTasks()` is true only for member.
- A **member account** must link to exactly one member record. This is enforced by the unique `users.member_id`, the CHECK `users_member_link_matches_role`, and `EnsureUserIsActive`, which rechecks role, active flag and member link on every request.
- **Administrators** are created only by:
  - the first-run `UserSeeder`, from `MEMBERFLOW_ADMIN_*` settings (it refuses once any admin exists);
  - `php artisan memberflow:create-admin`, with a hidden password prompt.

  There is no web page that creates or promotes administrators, and no public registration.
- **Protected last administrator:** a user cannot deactivate themselves, and the last active administrator cannot be removed.
- Former staff are labelled "Former staff" (`User::isFormerStaff()`). Their historical tasks and audit rows keep their original meaning.
- Permission matrix (Admin / Member / Guest): `docs/MemberFlow-Documentation.md` §10.

## 5. Authentication, authorization and account activation

- **Login:** custom `LoginController` using `Auth::attempt`, a throttle per email and IP, session regeneration, and generic error messages.
- **Authorization:** route middleware (`auth`, `active`, `role:*`), then policies, then Form Request `authorize()`, then service state checks.
- **Member activation:**
  - Walk-in member: Members → member → **Login account → Send activation invitation**.
  - Online applicant: sent automatically after email verification.
  - The token is 64 random characters; only its SHA-256 hash is stored. It works once, lasts 72 hours, and is rotated on resend.
  - The token can only create a **member** account bound to that member record and its current email. Concurrent acceptance creates exactly one account (tested with 8 parallel requests).
- **Member email change:** the member must confirm the new address from a relative-signed, 48-hour link while signed in (`AccountEmailController`).
- **Administrator email and password change:**
  - **Web:** Profile → password change, and Users → Edit (audited, but no confirmation step).
  - **CLI:** `memberflow:change-admin-credentials` (§14).
- Passwords are bcrypt (12 rounds), with rules from `Password::defaults()`: at least 8 characters, letters, mixed case, a number.

## 6. Membership, payment, approval and renewal

1. **Register** (admin, walk-in) or **apply** (public `/apply`). Both use `MembershipService::register()`, which creates a pending member and an application transaction with **fee and validity snapshots**.
2. **Pay:**
   - `PaymentService` locks the transaction row and parses amounts as strings into integer cents.
   - Partial payments are allowed; overpayment is rejected.
   - A UNIQUE idempotency key prevents double submission.
   - When the balance reaches 0, the transaction becomes **Paid**.
   - Before approval, a payment can be voided with a reason; `amount_paid` is then recomputed under the lock.
3. **Approve:**
   - Only **Paid** transactions can be approved.
   - **Online** applications also need a verified email; walk-ins are exempt.
   - An application starts on the approval date. A renewal continues from the old end date + 1 day if the membership is still active.
4. **Renew:** allowed from 60 days before expiry, or after expiry, with no other open transaction. The level may change.
5. **Cancel:** only when nothing has been paid.
6. **Expire:**
   - `memberflow:expire` is scheduled daily at 00:05 in `routes/console.php`. **It needs `php artisan schedule:work` or a cron job to run automatically.**
   - Administrators can also run it from **Transactions → Run expiry check**.
   - Task eligibility and the member portal already compare dates directly, so an ended membership stops counting even before the job runs.
7. **Numbers:**
   - members `MF-YYYY-NNNNN`;
   - transactions `TXN-YYYYMMDD-NNNNNN`;
   - receipts `OR-YYYY-NNNNNN`.

## 7. Tasks

- Administrators create, assign, reassign, reopen, archive and soft-delete tasks. Tasks are never hard-deleted, and the foreign keys use RESTRICT.
- Every assignment is recorded in `task_assignments`; every change is recorded in `task_progress_updates`.
- Assignees must be **member accounts with an active, current membership**.
- Members update status, progress (0–100) and notes on their **own** current tasks:
  - progress 100 means Completed, and completed tasks are locked;
  - previous assignees get read-only access.
- An optional related member is shown to the assignee as a **name only**.

## 8. Google Maps and OpenWeather

- **Maps:**
  - Loaded in the browser (`resources/js/location.js`) with `GOOGLE_MAPS_KEY`. The key must be HTTP-referrer-restricted in Google Cloud.
  - Used for the public picker (consent required), the admin read-only map, and the member's own map.
  - A missing or rejected key hides the map and shows a message; typing coordinates still works.
  - Uses `DEMO_MAP_ID` for advanced markers.
- **Weather:**
  - `WeatherService` calls OpenWeather **server-side** through the `/weather` JSON endpoint (throttled, cached, 5 s timeout, 3 s connect timeout). The key never reaches the browser and is never logged.
  - A rejected key shows an honest message.
- Details: `docs/maps-and-weather.md`.

## 9. Email, notifications, SMTP and queue

- **Every** email goes through `EmailDeliveryTracker`. A row in `email_deliveries` records an honest status: queued, sent ("Accepted by SMTP server"), logged ("NOT delivered"), failed, or skipped (fictional or reserved domains such as `example.com` or `.test`).
- There is **no silent fallback** from SMTP to the log.
- **Mail modes:** `MAIL_MAILER=smtp` (Gmail: `smtp.gmail.com`, port 587, STARTTLS via `MAIL_SCHEME=null`, App Password) or `log`.
- **Queue:**
  - The demo uses **`QUEUE_CONNECTION=sync`**: emails are sent right after the database commit, in the same request, and failures are recorded instead of thrown.
  - Production would use `database` plus `php artisan queue:work --tries=3`.
- **Links:**
  - Applicant verify and status links and member email-change links are **relative-signed** (they work on any host).
  - Invitation links use path tokens.
  - Links are removed from logs and from the `serve` request log.
- One-time content cannot be retried from the Email log (activation, email change, administrator code).
- Details: `docs/email-and-security.md`.

## 10. Database

- **21 tables:**
  - 12 application tables: `users`, `user_invitations`, `user_account_events`, `membership_categories`, `membership_levels`, `members`, `membership_transactions`, `payments`, `tasks`, `task_assignments`, `task_progress_updates`, `email_deliveries`;
  - Laravel support tables: `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `notifications`, `password_reset_tokens`, `migrations`.
- **19 migrations**, all Ran on main.
- **Constraints:**
  - 13 MariaDB **CHECK constraints** (money ranges, progress 0–100, valid roles, member-link rules, staff invitations need an inviter);
  - 29 foreign keys, mostly RESTRICT;
  - UNIQUE keys on numbers, emails, idempotency keys, token hashes and `users.member_id`.
- `amount_paid` is a deliberate cached aggregate. Tests and the audit check that it equals the sum of non-voided payments.
- Generated data dictionary: `docs/data-dictionary.md`. ERD: `docs/MemberFlow-Documentation.md` §7.

## 11. Testing

| Command | Purpose |
|---|---|
| `php artisan config:clear && php artisan test` | Full suite. **632 tests, 4,410 assertions, all passing** (Nov 24, 2026) |
| `php artisan test --filter=ChangeAdminCredentials` | One area (also `tests/Feature/<Area>`) |
| `vendor/bin/pint --test` | Code style (passes) |
| `npm run build` | Front-end build (passes) |
| `php artisan view:cache` then `php artisan view:clear` | Compiles all 99 Blade views |

- Tests run **only** against `memberflow_testing`:
  - `phpunit.xml` forces it with `force="true"`;
  - `tests/TestCase::setUpTraits()` calls `DatabaseSafety::assertSafeForTests()` before any database trait runs.
- Mail is faked or uses in-memory transports, and weather uses `Http::fake()`. **No test contacts Gmail, Google or OpenWeather.**
- Breakdown by area, plus E2E, concurrency and responsive results: `docs/testing-report.md`. It records the 623-test baseline (550 test methods) and keeps the R8 E2E, concurrency and responsive results.

## 12. Local XAMPP and MariaDB configuration

- **Project:** `C:\xampp\htdocs\laravelproject_Sarzuelo`.
- **Start:**
  1. Start MySQL in the XAMPP Control Panel.
  2. `npm run build` (or `npm run dev`).
  3. `php artisan serve` (LAN: `--host=0.0.0.0 --port=8000`; see `docs/lan-demo.md`).
- **MariaDB 10.4.32:**
  - listens on `127.0.0.1:3306`;
  - `datadir="C:/mysql_new/data"` (§3);
  - user `root` with a local-only password setting the owner controls.
- **Stop safely:** XAMPP Control Panel → Stop, or `C:\xampp\mysql\bin\mysqladmin.exe -u root shutdown`. **Never** kill `mysqld.exe`.
- `.env` keys used (names only): `APP_*`, `DB_*`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION`, `MAIL_*` (including `MAIL_SCHEME`, `MAIL_TIMEOUT`), `GOOGLE_MAPS_KEY`, `OPENWEATHER_KEY`, `APP_TIMEZONE=Asia/Manila`, `MEMBERFLOW_ADMIN_NAME/EMAIL/PASSWORD` (first-run seeder only).

## 13. Backups and recovery

- Backups live **outside the project** in `C:\memberflow_backups\`. They are SQL dumps named `memberflow_<purpose>_<timestamp>.sql`, each with a `.sha256` file, plus code snapshots. The newest is `memberflow_pre_admin_email_20261124_162339.sql`, which was restore-verified.
- **Procedure** (also in `docs/deployment.md` §3):
  1. Back up with `mysqldump --single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4 memberflow --result-file=…`. Leave out `--databases`, so a restore can never overwrite `memberflow` by accident.
  2. Record the SHA-256.
  3. Restore into a **new scratch database**.
  4. Compare row counts, `CHECKSUM TABLE`, the schema and foreign-key orphans.
  5. Drop the scratch database.
- **Before any approved change to main:**
  1. Take a fresh backup and check it.
  2. Snapshot (`COUNT(*)` + `CHECKSUM TABLE EXTENDED` per table).
  3. Do a dry run.
  4. Make the change.
  5. Re-snapshot and confirm only the expected tables and rows changed.

  The earlier helper scripts lived in a temporary scratch folder and are **not** in the repository; recreate them as needed.

## 14. Administrator evaluation account (no passwords)

- **User #1** is the single active administrator. Its sign-in email was moved to the owner's dedicated evaluation Gmail. The address is intentionally not written here.
- **Command:**
  ```
  php artisan memberflow:change-admin-credentials --user=1 --current-email=<old> --email=<new> [--dry-run]
  ```
  - Pre-checks: active admin, matching current email, unique and non-test new email, `MAIL_MAILER=smtp`, `SESSION_DRIVER=database`.
  - Hidden password prompts with no visible fallback.
  - An 8-digit code from `random_int` is emailed **only to the new address**. Only an HMAC of it is kept, in memory. It expires after 15 minutes, allows 3 attempts, works once, and one run at a time is enforced with a cache lock.
  - One transaction updates the email, password, `email_verified_at` and `remember_token`, deletes all of that user's sessions, and writes the audit rows `email_changed` and `password_changed`.
- **Execution history (Nov 24, 2026):**
  1. Backup `memberflow_pre_admin_email_20261124_162339.sql`, restore-verified.
  2. Dry run passed: only User #1 affected, 1 session to sign out.
  3. The **owner ran the command** in their own terminal.
  4. The owner then **confirmed that sign-in with the new credentials works**.
- **Read-only post-check: passed.**
  - Only User #1's email, password, `email_verified_at`, `remember_token` and `updated_at` changed.
  - Role, active status and member link are unchanged, and it is still the only active administrator.
  - Exactly 2 audit rows were written, and 1 code email went to the new address only.
  - All earlier sessions are gone, and no account uses the old email.
  - Every membership, transaction, payment and task table is unchanged.
- **Professor/evaluator member view:** activate a member account for a fictional member that uses a Gmail "+" alias of a test inbox (`docs/deployment.md` §4). Hand passwords over privately.
- `.env` still holds the old `MEMBERFLOW_ADMIN_EMAIL`. It is harmless because the seeder refuses when an admin exists, but the owner may update it.

## 15. Known limitations and remaining manual testing

See `docs/known-limitations.md`. Key points:
- no password-reset email flow;
- renewals and payments are handled by the office;
- receipts are acknowledgements, not tax receipts;
- no PDF or CSV export;
- `users.role` still defaults to `staff` in the schema;
- no full Content-Security-Policy;
- `DEMO_MAP_ID` is used for advanced markers;
- `localhost`/HTTPS is needed for "use my current location".

**Not yet verified manually:**
- a hands-on session on a physical phone;
- live Gmail delivery of activation and email-change emails (the administrator code email was delivered: it is recorded as sent, and the owner's run succeeded);
- production HTTPS hosting.

**Documentation cleanup (done Nov 24, 2026, after the initial commit):**
- Test counts updated to 632 tests / 4,410 assertions in the README, testing report, documentation §19, rubric matrix and demo script. The testing report's area table totals 559 test methods.
- Documentation §19 and the testing report now record both approved account changes to the main database (R6 and the administrator credential change).
- All screenshots were retaken from the final system. The staff-era images were replaced by `15-users.png`, `16-member-dashboard.png` and `20-member-task-update.png`, and `22-member-membership.png` was added. The user guides reference them.
- The owner's home LAN address in the documentation was replaced with the placeholder `<PC-LAN-IP>`. A few **tests** still use it as a sample host value; tests were intentionally left unchanged.

- The `/login` subtitle now reads "Administrators and members use the same sign-in page…". `AuthenticationTest` checks that it never mentions staff, and `01-login.png` was retaken.

## 16. InfinityFree hosting (planned; audit unfinished; **nothing deployed**)

The free-plan restrictions below come from InfinityFree forum and staff posts. Re-check them before deploying:
- no SSH, Artisan or Composer on the server;
- **cron disabled, and external cron services blocked**;
- the web root is fixed to `htdocs`, and **files outside `htdocs` are deleted**;
- an `open_basedir` restriction applies;
- no symlinks;
- an inode limit of about 30,000;
- a 10 MB file limit;
- outbound SMTP **only on port 587 with STARTTLS**;
- outbound HTTP is generally allowed, though some services are blocked;
- a **browser security check** (`aes.js` + `__test` cookie) blocks non-browser clients and **redirects a visitor's first request with `?i=1` appended**;
- nginx sits in front of Apache, so HTTPS arrives as `X-Forwarded-Proto`;
- PHP 8.3/8.4 and MySQL 8.0 / MariaDB 11.4 are advertised.

**Findings so far:**

| Item | Verdict |
|---|---|
| Size | Production `vendor/` is about 7,000 files and folders, plus about 400 for the app. That is below the inode limit. Build locally with `composer install --no-dev --optimize-autoloader` and `npm run build` |
| Layout | The whole app must sit inside `htdocs`, with a root `.htaccess` that rewrites everything into `public/`, plus `Require all denied` for every other folder and all dotfiles. **`.env` is therefore inside the web root, protected only by `.htaccess`.** Test it after upload: `/.env`, `/storage/logs/laravel.log`, `/composer.json` and `/vendor/...` must return 403/404. Use hosting-only credentials (a separate Gmail App Password, and referrer-restricted keys) |
| Scheduler | `memberflow:expire` and `memberflow:expire-invitations` **will not run.** Invitation expiry is already enforced when an invitation is opened. Membership end dates are already enforced for tasks and the portal. The stored "Expired" status needs the admin's **Run expiry check** button (operational step). Do **not** add a public trigger URL |
| Queue | `QUEUE_CONNECTION=sync` works. The `database` queue cannot work (no worker) |
| Sessions/cache | `database` drivers work on MySQL. Set `SESSION_SECURE_COOKIE=true` for HTTPS |
| Gmail | Port 587 STARTTLS matches the current config. It still needs a live test on the host |
| Maps | Works in the browser. The owner must add the hosted `https://<domain>/*` referrer to the key (provider setting; owner's decision) |
| OpenWeather | Outbound HTTPS is probably allowed; verify on the host |
| **Code change C1: done** | `URL::hasCorrectSignature(..., ignoreQuery: config('memberflow.signature_ignored_query'))` (`['i']`) in `ApplicantVerificationService::linkProblem()` and `AccountEmailController::confirm()`. Tests show links work with `&i=1`, while a changed signature or expiry, or any other extra parameter, is still rejected. Invitation links use path tokens and were never affected |
| **Code change C2: done** | `App\Support\HostingProxy::apply()`, called from `AppServiceProvider::boot()`, configures Laravel's global `TrustProxies` middleware from `TRUSTED_PROXIES` (`*` or IPs). It trusts `X-Forwarded-Proto`/`Port` only, never `X-Forwarded-Host`. `X-Forwarded-For` is trusted only with `TRUSTED_PROXY_FORWARDED_FOR=true`; `FORCE_HTTPS=true` forces https URLs. Nothing changes when these are unset. `HostingProxyTest` covers each case. **Still to check on the real host:** whether `$request->ip()` is per visitor (the IP-keyed throttles depend on it) before enabling `TRUSTED_PROXY_FORWARDED_FOR` |
| Artisan-free database | Build a **separate demo database** locally (for example `memberflow_demo`): migrate, seed categories and levels, create the single evaluation admin with `memberflow:create-admin` (hidden prompt), then optionally `DemoDataSeeder` (fictional `@example.test` people). Check its integrity, dump it without `--databases`, and import it through phpMyAdmin into the host database. **Never upload the main `memberflow` data.** Later schema changes: generate SQL with `php artisan migrate --pretend` on a local copy, review it, and run it in phpMyAdmin |
| Production `.env` | Write it from scratch. Never upload the local `.env` or the `.env.backup_*` file. Use `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL` and a new `APP_KEY` (old signed links stop working, which is acceptable for a new database). Do not upload `bootstrap/cache/config.php` (it holds absolute Windows paths), `storage/logs/*`, `tests/`, `docs/`, `node_modules/` or the course PDFs |
| Rollback | Keep the previous upload and SQL dump. Re-upload the previous files and re-import the previous dump into a fresh host database. The local main database is never involved |

**Recommendation:** **GO for a fictional-data classroom demonstration** now that C1 and C2 are implemented and tested, provided the post-upload checks in `deployment.md` §7 pass on the real host. **NO-GO for real member data**: secrets sit inside the web root behind `.htaccess` only, and there is no cron or worker. Deploying still needs the owner's hosting account and explicit approval.

## 17. Resuming in a new Claude account

1. Read this file, then `README.md`, `docs/MemberFlow-Documentation.md`, `docs/known-limitations.md` and `docs/deployment.md`.
2. Confirm the environment, read-only:
   - `php -v` (8.2.x);
   - MariaDB running on 127.0.0.1:3306;
   - `php artisan migrate:status` (19 Ran);
   - `php artisan config:clear && php artisan test` (632 passing).
3. Ask the owner which task to continue. Open items, roughly in priority order:
   1. deploying to InfinityFree with the owner (`deployment.md` §7), only after their approval;
   2. the remaining manual checks in §15 (physical phone, live Gmail delivery of member emails);
   3. making the GitHub repository private, if the owner wants that (owner's GitHub settings, §19).
4. Before **any** change to the main database, follow §13 and §18 and get explicit approval for the exact procedure.
5. Work in phases and stop at each phase boundary for owner approval. This is how R0–R8 were run, and the owner expects it.

## 18. Safety rules (project-specific; all still apply)

**Never:**
- `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback` or `db:wipe` against `memberflow`. `DatabaseSafety` blocks them outside `memberflow_testing`; never bypass it;
- reset or recreate the main database, recreate administrator accounts, or create extra administrators without approval;
- modify existing membership transactions or payments, or delete historical staff, task or audit records;
- run seeders against main without approval. `DemoDataSeeder` adds data permanently;
- touch the other projects' databases on the same server;
- force-kill `mysqld.exe`;
- print, log, commit or paste API keys, App Passwords, `APP_KEY`, passwords, password hashes, tokens or codes. Never ask the owner to paste one;
- change API keys or provider billing or settings, change firewall rules, or deploy online without explicit approval;
- send real emails from automated tests;
- create public maintenance or trigger endpoints to work around hosting limits;
- edit files with fragile inline regex scripts. Use the Edit tool. An inline PHP regex once emptied `TaskController.php`, which was restored from a code backup.

**Always:**
- run tests only on `memberflow_testing`;
- take a fresh verified backup before an approved main-database change;
- use dry run → approval → execute → read-only verification;
- use fictional data and Gmail "+" aliases for demos;
- report results honestly, including anything not verified.

## 19. GitHub, dependencies and environment

**State:**
- **Public** repository `github.com/xrixtine/MemberFlow` (remote `origin`, branch `main`).
  - The owner pushed the initial commit ("Initial MemberFlow project with complete Administrator-Member system", 367 files).
  - With the owner's approval, its author email was rewritten to the owner's GitHub no-reply address and force-pushed on Nov 24, 2026.
  - The privacy, documentation and hosting-compatibility work followed as a second commit.
  - The repository's local `user.email` is set to the no-reply address, so later commits use it too.
- No commit contains `.env` files, SQL dumps, logs, `vendor/`, `node_modules/` or course PDFs. Scans of the tree found no API keys, `APP_KEY` values or real account email addresses.
- Screenshots are included. The owner's temporary `/docs/screenshots/` ignore rule was removed after their privacy review.
- `.gitignore` was hardened on Nov 24, 2026 to exclude:
  - every `.env*` file except `.env.example`;
  - `*.sql`, dumps and checksum files;
  - logs and `storage/logs/*`;
  - `bootstrap/cache/*.php`;
  - keys and certificates;
  - `.claude/settings.local.json`;
  - the professor's course materials (`/files and instructions/`, kept locally);
  - backup, temporary and OS files;
  - plus the Laravel defaults (`vendor`, `node_modules`, `public/build`, `public/hot`, `.phpunit.cache`, IDE folders).
- Test files contain only obvious credential placeholders such as `abcd efgh ijkl mnop`. The only Gmail addresses in tracked files are package authors in `composer.lock` and `yourtest+…` / `your.address@…` placeholders.

**Privacy status:**
1. **Screenshots:** all 22 images are fictional-data captures from the final system. They passed a text scan for `.env` values, Gmail addresses and signed/token links, and 12 were also checked visually. The original set, including one that showed the owner's real Gmail address, is in `C:\memberflow_backups\screenshots_pre_R8_original_20261124\` (checksummed) and was never committed.
2. **Commit author email:** every commit on `main` uses the owner's GitHub no-reply address (history rewritten with approval). GitHub may keep the replaced commit reachable by its old ID for a while; the owner can ask GitHub Support to purge cached views if needed. Turning on GitHub → Settings → Emails → "Keep my email addresses private" also blocks future pushes that would expose the real address.
3. **LAN address:** the docs use `<PC-LAN-IP>`, and the tests use the generic sample `192.168.50.10`. The rewritten first commit still contains the owner's private LAN address in its content (low risk: a private, non-routable address).
4. **Repository visibility:** the repository is **public** (checked through GitHub's public API). Making it private is an owner setting (GitHub → repository → Settings → General → Danger Zone → Change visibility).

**Setup on a new machine:**
```
git clone https://github.com/xrixtine/MemberFlow.git laravelproject_Sarzuelo
cd laravelproject_Sarzuelo
composer install
npm ci && npm run build
copy .env.example .env            # owner fills DB, mail and API values; never commit it
php artisan key:generate          # ONLY for a fresh environment; it invalidates signed links of an existing database
```
- Create the databases `memberflow` and `memberflow_testing` (`utf8mb4_unicode_ci`).
- Then **either** restore the latest verified dump into `memberflow`, **or** run `php artisan migrate --seed` for a new empty system (the seeder needs `MEMBERFLOW_ADMIN_*` in `.env`).
- Tests need a local `.env.testing` (git-ignored) with at least:
  - `APP_ENV=testing` and its own `APP_KEY`;
  - `DB_DATABASE=memberflow_testing`;
  - `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`;
  - empty API keys.

  `phpunit.xml` forces the database and mailer anyway.

**Checklist for every later commit:**
1. `git status --ignored`: confirm `.env*`, `storage/logs`, `vendor`, `node_modules` and `files and instructions/` are ignored.
2. `git add -A --dry-run` and review the list.
3. Scan the staged files for keys, real email addresses and personal data.
4. Commit and push only after the owner approves.

## 20. Document map

| File | Purpose |
|---|---|
| `README.md` | Install, run, accounts, troubleshooting |
| `docs/MemberFlow-Documentation.md` | Full documentation (24 sections, ERD, workflows, guides) |
| `docs/data-dictionary.md` | Generated column-level dictionary |
| `docs/deployment.md` | Operation, backups and restore, accounts, production HTTPS checklist |
| `docs/email-and-security.md` | Mail modes, Gmail setup, queue, link security, log redaction |
| `docs/maps-and-weather.md` | Maps and weather setup and behaviour |
| `docs/lan-demo.md` | Same-Wi-Fi demonstration and restore steps |
| `docs/testing-report.md` | Test, E2E, concurrency, responsive and integrity results (counts as of R8) |
| `docs/known-limitations.md` | Limitations and unverified items |
| `docs/rubric-matrix.md` | Rubric self-assessment |
| `docs/demo-script.md` | 22-step presentation script |
| `docs/PROJECT_HANDOFF.md` | This file |

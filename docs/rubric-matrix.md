# MemberFlow — Rubric and requirement verification matrix

Source: `files and instructions/Laravel Project Instructions.pdf` (pages 1–2 are the requirements; page 3 is the rubric, 100 points).
This matrix maps each criterion to evidence. **It is a self-assessment, not a guarantee of any grade.**

Legend for evidence:
- **T** = automated test (PHPUnit, 575 passing);
- **E** = end-to-end workflow over real HTTP (R8, 48/48);
- **A** = read-only integrity audit of `memberflow` (R8, 32/32);
- **S** = screenshot in `docs/screenshots/`.

## A. Rubric criteria

| Criterion (points) | "Excellent" asks for | Implemented by | Routes / views | Tests | Manual / E2E evidence | Remaining gaps |
|---|---|---|---|---|---|---|
| **Laravel Setup and Database Connection (15)** | App runs; database, migrations, relationships fully work | Laravel 12 on XAMPP PHP 8.2 + MariaDB `memberflow`; 19 migrations (all applied); Eloquent relationships on all models; seeders; separate `memberflow_testing` | `php artisan migrate --seed`; 96 routes | T `SchemaIntegrityTest`, `ModelRelationshipTest`, `MemberAccountSchemaTest`, `SeederTest`, `DatabaseSafetyTest` | A: all migrations Ran, `CHECK TABLE` OK, no orphans across 29 foreign keys | None known |
| **CRUD: Members (20)** | Complete Create, Read, Update, Delete, validation, search | `MemberController` + `MembershipService`; Form Requests; deactivate/reactivate; soft delete only without transactions or login; search by name/number/email + filters by category, level, status, source | `/admin/members` (index, create, show, edit, history, print) — S 05, 06, 07, 08, 13 | T `MemberManagementTest` and related | E A/B: online and walk-in members, approval, records | "Delete" is a soft delete for members with no history; members with history are deactivated (by design) |
| **Category and Level Management (15)** | Complete CRUD with correct member assignment | `CategoryController`, `LevelController`; default 4 categories / 4 levels (fee, validity, benefits); in-use delete protection; active flag; required FKs | `/admin/categories`, `/admin/levels` — S 03, 04 | T `CategoryManagementTest`, `LevelManagementTest` | Fee snapshots keep history unchanged | None known |
| **Transaction Processing (20)** | Membership creation, renewal, payment, status, history work correctly | `MembershipService`, `PaymentService`, `TransactionStatus::canTransitionTo()`; Pending/Paid/Approved/Expired/Cancelled; TXN-/OR- numbers; partial payments; voids; only Paid → Approved; daily expiry; history; receipts; member portal shows the member's own history and receipts | `/admin/transactions`, `/admin/payments`, receipts, `/my/payments` — S 09–13 | T `Feature/Transactions`, `Feature/Payments`, `MemberPortalTest`, unit tests | E B (payment → approval → receipt), E D (renewal extends validity from the old end date); concurrency: 6 parallel payments → exactly 3 accepted; A: `amount_paid` = valid payments everywhere | None known |
| **Database Design (10)** | Proper tables, keys, relationships, normalization | 12 application tables, 3NF; FKs with RESTRICT; UNIQUE keys (incl. one login per member); 13 MariaDB CHECK constraints (money, progress, roles, member links); fee snapshots; assignment and audit history | `docs/data-dictionary.md` (generated from the live schema), ERD in `MemberFlow-Documentation.md` §7 | T `SchemaIntegrityTest`, `MemberAccountSchemaTest` | A: every CHECK holds for every row; numbers unique | `amount_paid` is a deliberate cached aggregate (verified equal to the valid payments) |
| **Interface and Usability (10)** | Clean, responsive, easy-to-use interface | Consistent Tailwind design system (charcoal/lime), reusable Blade components, admin and member dashboards, plain-language status messages, empty states, confirmations, accessible focus and contrast | All pages — S 01–21 | T dashboard/report/portal view tests | Real Chrome: 43 pages × 375/768/1440 px, 0 overflow; drawer; focus; WCAG AA contrast for all pairs | A short hands-on check on a physical phone is still recommended |
| **Code Quality (5)** | Organized MVC, readable code, proper validation | Thin controllers, service layer, Form Requests, policies, enums, events/listeners, CLI commands; Laravel Pint; no extra packages | `app/` structure (Documentation §9) | 575 tests pass; `pint --test` passes | `view:cache` compiles all 99 views; static scan clean | No PHPStan/Larastan (not installed, to avoid new dependencies) |
| **Documentation and Presentation (5)** | Complete documentation, screenshots, clear demonstration | README, full documentation (24 sections), data dictionary, deployment guide, testing report, this matrix, demo script, email/security and maps/weather guides; screenshots | `README.md`, `docs/` | — | `docs/demo-script.md` (22 steps) | The live presentation itself is up to the presenter; rehearse with the script |

## B. Requirements from the instructions (pages 1–2)

| Requirement | Status | Where |
|---|---|---|
| Add / view / edit / delete-or-deactivate member | ✔ | `MemberController` |
| Search members by name, category or level | ✔ | Members index filters |
| Categories Student, Regular, Senior Citizen, Corporate | ✔ | `CategorySeeder` |
| Levels Basic, Silver, Gold, Platinum with fee, validity, benefits | ✔ | `LevelSeeder` |
| Create membership application transaction | ✔ | `TransactionController@store`, `MembershipService::openApplication()` |
| Process membership renewal | ✔ | `MembershipService::openRenewal()` (60-day window, level may change); E D |
| Record payment transactions | ✔ | `PaymentController`, `PaymentService` |
| Official transaction/reference number | ✔ | `TXN-YYYYMMDD-NNNNNN` (+ `OR-YYYY-NNNNNN` receipts, `MF-YYYY-NNNNN` members) |
| Status: Pending, Paid, Approved, Expired, Cancelled | ✔ | `TransactionStatus` enum + transition map |
| Transaction history per member | ✔ | `/admin/members/{member}/history`; the member's own `/my/membership` |
| MySQL via migrations, models, Eloquent | ✔ | 19 migrations |
| Suggested tables users, members, membership_categories, membership_levels, membership_transactions, payments | ✔ | All six present (plus supporting tables) |
| Models Member, MembershipCategory, MembershipLevel, MembershipTransaction, Payment | ✔ | `app/Models` |
| Controllers Member, Category, Level, Transaction, Payment | ✔ | `app/Http/Controllers` |
| Migrations for all tables | ✔ | `database/migrations` |
| Blade views for forms, lists, details, dashboards | ✔ | `resources/views` |
| Validation: required fields, email format, duplicate membership number, payment amount | ✔ / note | Required + `email:rfc` + unique email; membership numbers are **system-generated** and protected by a UNIQUE index, so a duplicate cannot be entered; payment amount regex, > 0, ≤ balance |
| Authentication using Laravel's built-in authentication | ✔ | Laravel session guard (`Auth::attempt`, `auth` middleware), custom login page; exactly two roles (administrator, member); no public registration |
| Suggested pages (login, dashboard, members, categories, levels, transactions, payment recording, history, printable record/receipt) | ✔ | All present — S 01–13 |
| Dashboard with total members, active members, pending transactions, collected payments | ✔ | Admin dashboard (+ more figures); member dashboard for each member |
| Only paid transactions may be approved | ✔ | Transition map |
| Payments connected to a valid transaction | ✔ | FK + `PaymentService` checks |
| "Role-Based Task Management" (project title) | ✔ | Administrators assign tasks to members with an active membership; members update their own; E C |

## C. Items not fully verified

1. **A hands-on session on a physical phone**: the LAN page and map were verified in Chrome at phone size; do a short check on the iPhone in the rehearsal.
2. **Gmail delivery of the member-account emails**: Gmail delivery itself was verified live (Phase 7b); send one activation email to a "+" alias during the rehearsal.
3. **"Duplicate membership number" validation**: satisfied by design (generated numbers + UNIQUE index) rather than a user-entered field. Mention this during the presentation.

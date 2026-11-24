# MemberFlow — Testing report (final, after revision R8 and the administrator credential command)

Date of the latest full run: November 24, 2026. Every test that changes data used the isolated database `memberflow_testing` or a disposable copy. The main `memberflow` database's account records were changed only by explicitly approved operations: the R6 account migration (two user rows) and the owner's run of `memberflow:change-admin-credentials` for the administrator account (§7). No membership, transaction, payment or task record was ever changed by testing.

## 1. Automated test suite

Command: `php artisan config:clear && php artisan test`

**Latest verified baseline: 632 tests passed, 4,410 assertions, 0 failures.** (The R8 run had 575 tests and 3,742 assertions. Later tests cover the administrator credential command (48) and the shared-hosting compatibility changes: host-added `?i=1` on signed links, and HTTPS behind a proxy (9).)

| Area | Test files | Test methods |
|---|---|---|
| Unit (money parsing, number formats, status transitions, one-time codes) | `tests/Unit/*` | 19 |
| Authentication, sign-in roles, authorization, account-creation paths | `Feature/Auth/*` | 26 |
| Members, categories, levels, member authentication, member portal, isolation, activation, account management | `Feature/Members/*` | 111 |
| Applications, renewals, approval, cancellation, expiry | `Feature/Transactions/*` | 45 |
| Payments (recording, idempotency, voids, receipts) | `Feature/Payments/*` | 37 |
| Tasks (admin, member eligibility, progress, dashboards) | `Feature/Tasks/*` | 49 |
| Invitations, user management, member-account schema, staff retirement (R6), admin CLI, administrator email/password change | `Feature/Accounts/*` | 85 |
| Public application, verification, notifications, delivery tracking, sync queue | `Feature/Email/*` | 88 |
| Maps (location, consent, member location) and weather (`Http::fake`) | `Feature/Integrations/*` | 32 |
| Dashboards and reports (hand-checkable fixed data set) | `Feature/Reports/*` | 16 |
| Schema integrity, database safety, seeders, relationships, profile, security headers, demo seeder, hosting proxy/HTTPS | other `Feature/*` | 51 |

Data-provider cases add executions beyond the 559 test methods, which is why the total is 632.

The end-to-end, concurrency, responsive and integrity results below (§2–§6) come from the R8 verification round. They were not repeated after the administrator credential command and the hosting changes were added; those are covered by their own automated tests.

**External services are simulated.** Email uses the in-memory `array` mailer and `Notification::fake()`; weather uses `Http::fake()`. No automated test contacts Gmail, Google or OpenWeather.

## 2. End-to-end workflows over real HTTP

**48 / 48 checks passed.** These were real HTTP requests (curl with separate cookie jars and CSRF tokens) to a PHP server on `memberflow_testing`, with fictional people and log-only mail. Real Chrome was used for maps.

| Workflow | Checks | What was verified |
|---|---|---|
| **A** Public application → member login | 11 | Application accepted; pending online member + application; coordinates stored with consent; signed verification link; automatic member invitation (no inviter); verification never approves or pays; activation page; account created as **member** linked to the record (a posted `role=admin` is ignored); pending dashboard ("waiting for payment"); sign-in with the chosen password |
| **B** Payment → approval → receipt | 7 | Full payment with an OR number; approval → Active with validity dates; admin receipt and printable record; member sees Active and validity; member opens own receipt and prints own record |
| **C** Member task lifecycle | 6 | Admin assigns to an active member; member sees it; progress 50%; completion; admin sees completion and timeline; completed task locked (403) |
| **D** Renewal | 4 | Member sees "Renewal available"; admin creates the renewal; payment + approval extend validity from the old end date (2026-12-13 → 2027-12-13); member sees the new date |
| **E** Account security | 10 | Deactivated member signed out on the next request and cannot sign in; reactivation restores access; member refused admin dashboard, another member's page, reports, another member's receipt and users (403); guest redirected to login |
| **F** Maps and weather (Chrome) | 10 | Public map picker; admin read-only map of consented coordinates; live weather at the member location; member's own map at 375 px with live weather; no OpenWeather key in any page; **rejected Maps key** → map hidden with "unavailable" message (`InvalidKeyMapError`) and manual entry still works; **rejected OpenWeather key** → honest "rejected the API key" message, no invented weather; **map renders on the LAN address used by the iPhone** (`http://<PC-LAN-IP>:8000`) |

Earlier phase-specific browser checks also passed:
- R7 picker interactions, 22 / 22: click to place, drag, manual entry, clear, current location, consent refusal and acceptance, admin and member maps.
- R7 live OpenWeather, 12 / 12: values match the provider response, caching, key never exposed.

## 3. Concurrency (separate processes, separate MySQL connections)

| Test | Result |
|---|---|
| 6 × ₱300 payments on one ₱1,000 transaction | **PASS**: exactly 3 accepted, 3 rejected for exceeding the balance |
| 8 × acceptance of the same member invitation | **PASS**: exactly 1 account, 7 refused as "already used" |
| 6 × completion of the same task by its member | **PASS**: exactly 1 completion recorded, 5 refused (completed tasks are locked) |

## 4. Responsive, accessibility and print checks

Real Chrome (installed browser, driven by Playwright from outside the project) signed in through the login form and rendered **43 pages** (admin, member, public) at **375, 768 and 1440 px**.

| Check | Result |
|---|---|
| Horizontal page overflow | **0** in 132 page/width checks (wide tables scroll inside their own card) |
| Mobile navigation drawer | Opens from "Menu" for administrators and members |
| Keyboard focus | Visible focus on every control reached by Tab on the login page |
| Colour contrast (WCAG AA, 4.5:1) | All text pairs pass (body 13.1:1, secondary text 5.2–5.8:1, sidebar 4.7–7.5:1, lime/charcoal 12.1:1); all 7 badge styles pass (4.95–12.1:1) |
| Print layouts | Member record, receipts and reports render with navigation hidden (HTTP 200 for every print page) |
| Blade | `php artisan view:cache` compiled all 99 views without errors |

**Not done:** a hands-on session on a physical phone. The LAN page load was verified in Chrome at phone size.

## 5. Static and configuration checks

- `vendor/bin/pint --test` passes.
- `npm run build` passes.
- No `dd()`/`dump()`, no unescaped `{!! !!}` output, no `$request->all()` mass assignment, no CSRF exceptions, no `env()` outside config, no redirects built from user input.
- Routes:
  - all 65 admin routes require `auth` + `active` + `role:admin`;
  - all 11 member routes require `role:member`;
  - no staff middleware remains.
- `APP_DEBUG=false`; bcrypt with 12 rounds; session cookies HTTP-only and SameSite=Lax.
- A scan of about 12,500 project files found the API keys only in `.env`; no key appears in code, documents, logs or built assets.

## 6. Final database integrity audit (`memberflow`, read-only)

**32 / 32 checks passed:**
- every CHECK constraint holds for every stored row (13 constraints);
- membership, reference, receipt, idempotency and email values are unique;
- `amount_paid` equals the sum of non-voided payments for every transaction, and paid/approved transactions have no balance;
- the voided ₱1,000 is excluded from the ₱3,500 collected;
- only `admin`, `member` and `legacy_staff` roles exist;
- no member has two logins, and every member account points to an existing member;
- user #3 is linked to MF-2026-00002, user #2 is `legacy_staff`, and user #1 is an active administrator;
- task #1 is still Completed with its assignment and all 4 activity rows;
- no orphaned rows across 29 foreign keys; all 21 tables pass `CHECK TABLE`; all 19 migrations have run.

## 7. Main database protection

- Before each phase, a fresh `mysqldump` was taken and verified by restoring it into a scratch database (identical row counts, checksums and row fingerprints).
- Schema changes (R1, R3) and the R6 account migration ran only after explicit approval, a fresh backup, a rehearsal on a restored copy, and a dry run.
- The administrator credential change (`memberflow:change-admin-credentials`, Nov 24, 2026) followed the same pattern: a fresh backup restore-verified in a scratch database, a baseline, and a read-only dry run that showed only administrator User #1 would change. The owner then ran the command at hidden prompts and confirmed the new sign-in works. A read-only post-check confirmed:
  - only User #1's email, password, verification time and remember token changed;
  - role, active status and member link are unchanged, and it is still the only active administrator;
  - exactly two audit entries ("email_changed", "password_changed") were written;
  - one code email went to the new address only, was recorded as sent and stored no content;
  - every earlier session was removed, and no account uses the old email;
  - membership, transaction, payment and task tables are unchanged.
- After every other activity, the business tables of `memberflow` were compared and found identical. The runtime tables `sessions` and `cache` change through normal use of the running app.

## 8. What was NOT verified

| Item | Status |
|---|---|
| Physical phone session | Not performed by the automated checks (the LAN page was verified in Chrome at phone size). Do a short check on the iPhone during the rehearsal |
| Gmail delivery of the new member-account emails | Not sent during testing (fictional recipients or log mode). Gmail delivery itself was verified live in Phase 7b; confirm during the rehearsal with a "+" alias |
| Production HTTPS hosting on InfinityFree | The code changes are tested (§1), but nothing is deployed. Run the post-upload checks in `deployment.md` §7 on the real host |
| Production HTTPS hosting | Not done (out of scope); see `deployment.md` §6 |

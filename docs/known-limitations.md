# MemberFlow — Known limitations

Final status after revision R8 (November 24, 2026).

## 1. Verification status of external integrations

| Integration | Status | What was actually verified |
|---|---|---|
| Gmail SMTP | **Verified live** (Phase 7b) | A test email was accepted by Gmail and confirmed in the inbox. The member-account activation and email-change emails were tested with the in-memory mailer and log mode only; confirm one of each in the rehearsal with a Gmail "+" alias |
| Google Maps JavaScript API | **Verified live** (R7, R8) | Map loads with no provider errors on `127.0.0.1` and on the LAN address `http://<PC-LAN-IP>:8000`, so the API is enabled and billing is active. Click, drag, manual entry, clear, current location, consent, and the admin and member read-only maps were all checked in Chrome. A rejected key shows "unavailable" and manual entry keeps working |
| OpenWeather Current Weather | **Verified live** (R7, R8) | Real observations; displayed values match the provider response; caching; a rejected key shows an honest message |

## 2. Other verification not performed

| Item | Why | Mitigation |
|---|---|---|
| Hands-on session on a physical phone | The automated checks used Chrome at phone sizes, plus a real page load on the LAN address | Spend two minutes on the iPhone during the rehearsal (`lan-demo.md`) |
| Screen-reader walkthrough | Not performed | Semantic headings and labels, `aria-live` regions, visible focus and WCAG AA contrast were verified |
| Static analysis beyond style | Only Laravel Pint and Blade compilation | PHPStan/Larastan were not added, to avoid new dependencies |

## 3. Functional limitations (by design or out of scope)

- **Password reset.** Members cannot reset a forgotten password themselves; there is no "forgot password" email flow. An administrator can deactivate and, if needed, re-issue access. A reset flow would be a separate addition.
- **Renewals and payments are office-driven.** Members see renewal eligibility and their balance, but cannot renew or pay online.
- **Member details are office-managed.** Members change only their password. A changed email becomes the sign-in email only after the member confirms it.
- **Member deletion.** Members with transactions or a login account cannot be deleted, only deactivated, to protect history.
- **Payments** cannot be edited. Mistakes are voided (with a reason) before approval.
- **Receipts** are acknowledgement receipts, not official tax receipts (stated on the receipt).
- **Reports:**
  - Reports print through the browser; there is no PDF or CSV export.
  - Periods are limited to about 3 years.
  - Status sections are snapshots of "now".
- **Notifications** are emails plus a summary on the member dashboard; there is no in-app inbox with read/unread state.
- **Email** is "Accepted by SMTP server" at best from MemberFlow's point of view; inbox delivery can only be confirmed by the recipient. Reserved test domains (`example.com`, `.test`, …) are always skipped.
- **Email links** point to `APP_URL` at the time they were sent. Links sent while `APP_URL` was the LAN address won't open on another network.
- **"Use my current location"** needs HTTPS or localhost (browser rule). On the phone over plain HTTP, tap the map or type the coordinates.
- **Maps** use Google's `DEMO_MAP_ID` for advanced markers. That's fine for a school demo; production should set its own `GOOGLE_MAPS_MAP_ID`.
- **The `users.role` column default** is still the historical value `staff`, which can never sign in. Every code path sets the role explicitly; changing the default would need a schema migration.
- **Historical records** keep their original meaning:
  - the two accepted staff invitations, the "staff" activation audit entries and tasks done by former staff stay as they were;
  - former staff are labelled "Former staff".
- **No full Content-Security-Policy** beyond `frame-ancestors` (Google Maps needs several script origins). Recommended for production.
- **Hosting:** the app runs with `php artisan serve` over HTTP. Production needs HTTPS and the rest of the checklist in `deployment.md` §6.
- **InfinityFree free hosting** (prepared, not deployed; `deployment.md` §7):
  - the code handles the host's `?i=1` and its HTTPS proxy;
  - the plan still puts `.env` inside the web root, protected only by `.htaccess`;
  - it has no cron or queue worker: run **Run expiry check** manually, and keep `QUEUE_CONNECTION=sync`;
  - its browser check blocks non-browser clients, such as API tools.

  Use it for fictional-data demonstrations only.

## 4. Things to remember on demo day

- `APP_DEBUG=false`, `QUEUE_CONNECTION=sync`, then `php artisan config:clear`.
- Stop MariaDB from the XAMPP Control Panel (or `mysqladmin shutdown`), never with Task Manager.
- Take a verified backup before seeding demo data; `DemoDataSeeder` adds fictional records to the main database permanently.

# MemberFlow — Demonstration script

About **25 minutes**, 22 steps. All people and records are **fictional**. Each step lists what to do, the **expected result**, and a short **speaking point** (💬).

## A. Preparation

### The day before
1. **XAMPP:** start **MySQL** (and Apache if you want phpMyAdmin).
2. **Build, test and clear caches:**
   ```
   cd C:\xampp\htdocs\laravelproject_Sarzuelo
   php artisan config:clear
   npm run build
   php artisan test          # expect: 575 passed (uses memberflow_testing only)
   ```
3. **Back up the database** (see `deployment.md` §3) before adding any demo data.
4. **Demo data (optional):** `php artisan db:seed --class=DemoDataSeeder` adds 12 fictional members (one ready to renew, one expired), **2 demo member logins** and 5 tasks to the **main** database. It is additive and cannot be undone automatically, so decide first. Note the demo member password it prints once.
5. **`.env` for the demo:**
   - `QUEUE_CONNECTION=sync`, so emails are sent immediately and no worker is needed;
   - `APP_DEBUG=false`;
   - `MAIL_MAILER=smtp` (Gmail, verified). Use **Gmail "+" aliases of a test inbox you control** for the people you create live, for example `yourtest+walkin@gmail.com` and `yourtest+applicant@gmail.com`;
   - **no internet:** `MAIL_MAILER=log`, and get links with `php artisan memberflow:invitation-link EMAIL` and `php artisan memberflow:verification-link EMAIL`.

   Then run `php artisan config:clear`.
6. **Rehearse once,** including opening the activation and verification emails (check Spam).

### 30 minutes before
1. **Terminal:** `php artisan serve` (or `php artisan serve --host=0.0.0.0 --port=8000` for the phone demo, see `lan-demo.md`).
2. **Browser tabs:**
   - **Tab 1:** `http://127.0.0.1:8000/login` (administrator).
   - **Tab 2:** a private/incognito window for the member and the public applicant.
   - **Tab 3:** the test inbox (Gmail).
3. **Prepare these fictional details:**
   - walk-in member "Rina Demo-Live", `yourtest+walkin@gmail.com`;
   - applicant "Pia Demo-Online", `yourtest+applicant@gmail.com`.

## B. Script

| # | Do this | Expected result | 💬 Speaking point |
|---|---|---|---|
| 1 | **Administrator login** at `/login` | Admin dashboard | "Laravel session authentication. Exactly two roles can sign in, administrator and member; there is no public sign-up. New administrators are created only on the server with `memberflow:create-admin`." |
| 2 | **Dashboard overview**: figure cards, recent transactions, payments, task activity, email panel, weather | Live figures; live Manila weather | "Every number is a live database query. Voided payments are excluded from collections." |
| 3 | **Categories and levels**: open Levels; edit a level's benefits | 4 categories, 4 levels with fee, validity, benefits | "A level in use can't be deleted. A fee change never alters past transactions, because each one stores a snapshot." |
| 4 | **Walk-in registration**: Members → Register member → Rina Demo-Live, Regular, **Gold**, click a point on the **map** and tick consent, keep "Create a membership application now" → Register | Profile with `MF-2026-#####`, status **Pending**, read-only map | "Coordinates are stored only with explicit consent; the server refuses them otherwise." |
| 5 | **Partial payment**: open the application → Record payment → **800**, Cash | Receipt `OR-2026-######`; balance ₱1,200.00; still Pending | "Money is integer centavos. Overpaying is refused; a double click can't record twice (idempotency key)." |
| 6 | **Full payment**: Record payment → **1200**, GCash | Status **Paid** | "Only a zero balance makes it Paid." |
| 7 | **Approval**: Approve → confirm | **Approved**; member **Active**, valid today → 12 months − 1 day | "Only paid transactions can be approved; the server refuses anything else." |
| 8 | **Member login account (walk-in)**: on Rina's profile → Login account → **Send activation invitation** | "Invitation pending"; email sent | "No account exists until Rina chooses her password. Only a hash of the one-time link is stored, and the link is tied to Rina's record and email." |
| 9 | **Activation**: private window → open the email → choose a password | Signed in to **Rina's member dashboard**: Active, valid until …, ₱0 balance | "The account is a member account linked to exactly one membership record; any role sent with the form is ignored." |
| 10 | **Member portal**: Membership, Payments, Receipt, Print my record | Her own record, both payments, printable receipt | "Members only ever see their own data: other members' pages return 403, even by typing the URL." |
| 11 | **Task assignment**: admin → Tasks → New task → "Prepare welcome kits", High, due in 3 days → assignee list shows Rina (number · level · active) | Task created; Rina emailed | "Only members with an active, current membership can receive tasks; the server re-checks it." |
| 12 | **Member progress**: as Rina → My Tasks → In Progress, 60%, note → Save; then Completed | Progress bar, timeline; then Completed and locked | "Completing notifies the administrator. A completed task is locked until an administrator reopens it." |
| 13 | **Admin monitoring**: admin → the task, Dashboard activity, Reports → Task workload | Update and completion visible | "Every assignment and update is kept in history." |
| 14 | **Renewal**: open **Kara Demo-Expiring** (DemoDataSeeder; expires in ~20 days) → Renew → pay → approve | New period starts the day after her old end date | "Renewal opens 60 days before expiry, and early renewal never loses days. The member's portal shows 'Renewal available'." |
| 15 | **Public application**: private window → `/apply` → Pia Demo-Online, `yourtest+applicant@gmail.com`, Student, Silver, privacy → Submit | "Check your email" page | "Honeypot, minimum fill time, rate limits; the same answer whether or not the email exists." |
| 16 | **Email verification**: inbox → Verify | "Your email is verified" + "we also emailed you a link to activate your member account" | "Verification never approves or pays anything." |
| 17 | **Automatic member invitation**: inbox → "Activate your MemberFlow member account" → set password | Pia's dashboard: "Your application is waiting for payment" | "Online applicants get their login automatically after proving their email." |
| 18 | **Account security**: admin → Users → Pia → Deactivate; refresh Pia's tab | Pia signed out; login refused; Reactivate restores access | "Deactivation takes effect on the next click; history is kept." |
| 19 | **Maps and weather**: Pia/Rina → Membership shows her own map and live weather; admin dashboard weather | Real map and observation ("Observed … · Weather data © OpenWeather") | "The weather key never reaches the browser; coordinates are rounded to about 1 km before leaving the server." |
| 20 | **Reports**: Reports → This month → Print preview | All sections; print hides navigation | "Period sections use the chosen dates; snapshots show the situation now." |
| 21 | **Email log**: show statuses | "Accepted by SMTP server", "Skipped (fictional recipient)" | "Accepted by Gmail isn't 'in the inbox' — we say exactly what happened." |
| 22 | **(Optional) phone**: open the LAN address on a phone on the same Wi-Fi (`lan-demo.md`) | Same app, map works | "The same signed links work on the phone because email links use `APP_URL`." |

### Closing (1 minute)
Mention testing:
- 575 automated tests;
- 48 end-to-end checks over real HTTP;
- concurrency checks;
- 132 responsive checks;
- live Maps/OpenWeather verification;
- tests use a separate database, and the main data is protected.

## C. If something goes wrong

| Problem | Quick fix |
|---|---|
| Email doesn't arrive | Check the Email log. **Failed** → read the reason and Retry. Or get the link with the development commands |
| Member can't sign in | Account or member deactivated, or not activated yet (send the invitation) |
| Assignee list is empty | Only members with an **active** membership and an activated login are listed |
| "Too many login attempts" | Wait one minute |
| Map or weather "unavailable" | Key missing or not allowed for this address. Type coordinates manually |
| Page unstyled | `npm run build` |
| Renewal button missing | The member's renewal window isn't open. Use Kara Demo-Expiring |

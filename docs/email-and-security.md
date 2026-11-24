# MemberFlow — Email delivery, queue and link security

## 1. Mail modes

Set exactly one mode in `.env`. MemberFlow never switches modes on its own, and it never falls back from SMTP to the log.

| `MAIL_MAILER` | What happens | Status shown in the Email log |
|---|---|---|
| `log` (default, local testing) | Each email is written to `storage/logs/mail.log` and is **not delivered**. Activation and verification links are removed from the logged copy. | **Local log only — NOT delivered** |
| `smtp` (real delivery) | The email is handed to the SMTP server, such as Gmail. | **Accepted by SMTP server** if the server accepted it; **Delivery failed** if it did not |

Recipients on reserved or fictional domains are recorded as **Skipped** and never dispatched. This covers `example.com`, `example.net`, `example.org` and anything ending in `.test`, `.example`, `.invalid` or `.localhost`.

**"Accepted by SMTP server" is not proof of inbox delivery.** It means Gmail took responsibility for the message. The email can still land in spam or bounce later. Delivery is only confirmed when someone sees the message in the recipient's inbox (the test email says so in its text).

## 2. Gmail SMTP setup (done by the project owner)

1. Use a Gmail account you control. A dedicated test account is recommended.
2. Turn on **2-Step Verification**: Google Account → Security → 2-Step Verification.
3. Create an **App Password**: Google Account → Security → 2-Step Verification → *App passwords* (or go to `https://myaccount.google.com/apppasswords`). Name it "MemberFlow" and copy the 16-character password.
4. Edit `.env` yourself and **never paste the password into chats, tickets or terminal commands**:

   ```
   MAIL_MAILER=smtp
   MAIL_SCHEME=null          # Laravel 12: null/smtp = STARTTLS on port 587; "smtps" = implicit TLS on port 465
   MAIL_HOST=smtp.gmail.com
   MAIL_PORT=587
   MAIL_USERNAME=your.address@gmail.com
   MAIL_PASSWORD=the-16-character-app-password   # spaces may be removed
   MAIL_FROM_ADDRESS=your.address@gmail.com     # Gmail rewrites other From addresses
   MAIL_FROM_NAME="MemberFlow"
   ```

   Laravel 12 replaced the older `MAIL_ENCRYPTION` setting with `MAIL_SCHEME`. Leave `MAIL_ENCRYPTION` out.
5. Apply the change: `php artisan config:clear`, then **restart** `php artisan queue:work` (a running worker keeps the old settings).
6. Sign in as an administrator and go to **Email log → Send test email to me**. The test is sent to your own account email, so that address must be a real inbox. Change it under Profile if needed.
7. Check that the row shows **Accepted by SMTP server**, then confirm the message actually arrived in the inbox (also check Spam).

A wrong App Password shows **Delivery failed** with Gmail's reason, for example "Username and Password not accepted". It never shows *logged* or *sent*. Credentials are removed from stored error messages.

To return to local testing, set `MAIL_MAILER=log`, run `php artisan config:clear` and restart the worker.

## 3. Queue: `sync` for demos, `database` for production

Every email goes through `EmailDeliveryTracker` and is dispatched only **after** the database transaction commits, so a rolled-back action never sends email.
- With `QUEUE_CONNECTION=database` (production), emails are queued notifications processed by `php artisan queue:work`.
- With `QUEUE_CONNECTION=sync` (classroom demo, below), they are sent in the request.

### Local demo without a worker: `QUEUE_CONNECTION=sync`

For a local demonstration you can set `QUEUE_CONNECTION=sync` in `.env` (then run `php artisan config:clear`).

**How it behaves:**
- Each email is sent **in the same request**, right after the database transaction commits. A rolled-back action still sends nothing.
- Statuses, links, deduplication and the Email log work the same way.
- If sending fails, the delivery is recorded as **Failed** with a safe reason, and the action itself still succeeds; nothing is rolled back and no error page appears. Use **Retry** on the Email log.

**Limitations (why production should use `database`):**
- **No automatic retries or back-off.** A temporary SMTP problem means a manual Retry.
- **Slower actions.** Each action that sends email waits for the mail server: Gmail can take 1–25 s, bounded by `MAIL_TIMEOUT` (20 s). `php artisan serve` handles one request at a time, so other users wait too.
- **No isolation.** Mail-server slowness is felt by the person clicking the button.
- **Invitations.** The worker's encrypted-queue protection for invitation links isn't involved, because nothing is stored. The link only lives in memory for that request.

To switch back: set `QUEUE_CONNECTION=database`, run `php artisan config:clear`, and start `php artisan queue:work`.

```
php artisan queue:work --tries=3
```

- Keep the worker running in its own terminal while you use the app. Emails stay **Queued** until it runs. The dashboard and Email log warn when an email has been queued for more than 5 minutes.
- Each email is attempted 3 times (30 s, then 120 s apart). After the last attempt it is marked **Failed** and the job is kept in `failed_jobs`.
- To retry a failure, either click **Retry** on the Email log, which queues a fresh copy and marks the old row "retried", or run `php artisan queue:retry all`. Use one or the other, not both. A delivery that was already sent or retried is never sent again.
- Activation invitations and sign-in email confirmations cannot be retried from the log, because their one-time link is not stored. Use **Resend** (on the member's Login account card or the invitation) instead; it issues a new link and invalidates the old one.
- After changing `.env`, run `php artisan config:clear` and restart the worker.

## 4. Local testing without a real inbox

With `MAIL_MAILER=log`, links are removed from `mail.log` on purpose. Get a link with these development-only commands. They refuse to run when `APP_ENV=production` and print only to your terminal:

```
php artisan memberflow:invitation-link member@example.com         # member activation: resends with a NEW link (the old one stops working)
php artisan memberflow:verification-link applicant@example.com    # signed verify + status links
```

## 5. Link security

| Link | Protection |
|---|---|
| Member activation `/invitations/{token}` | 64-character random token. Only its SHA-256 hash is stored. Single use, 72-hour expiry, rotated on resend. Bound to the member record and its current email; it can only create a **member** account. While queued, the email job is **encrypted** with `APP_KEY`. Old staff invitations can no longer be accepted or resent. |
| Sign-in email change `/account/email/confirm/{user}/{hash}` | HMAC-signed (relative), 48-hour expiry, bound to a hash of the new email. Sent only to the new address and works only for the member who is **signed in** to that account, so the new mailbox alone cannot take over an account. |
| Applicant verify `/apply/verify/{public_id}/{hash}` | HMAC-signed with **relative** signing (valid on any host), 48-hour expiry. Bound to the member's ULID and a hash of the current email, so changing the email kills old links. |
| Application status `/apply/status/{public_id}/{hash}` | Same as the verify link, with a 30-day expiry. Shows first name, reference, statuses and amounts only. |

- These pages send `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex` and `Cache-Control: no-store`, and are rate-limited.
- Changing `APP_KEY` invalidates every signed link and every queued encrypted job.

### Where these links can still be seen

A link that is clicked has to travel to the server, so it **cannot be made invisible to every component**. The browser history, the recipient's mailbox, and any proxy or server access log can all hold it. MemberFlow limits the damage: links are single-use or short-lived, and a resend invalidates the old invitation link. It also keeps links out of its own records:

- **Application logs:** every `single`/`daily` log record passes through a redaction step that replaces invitation tokens and `signature=` values with `[secure link removed]`. Application code never logs these links.
- **Mail log:** in log mode, links are removed before the email is written.
- **Admin pages and the Email log:** they never show tokens or links.
- **`php artisan serve`:** MemberFlow overrides the `serve` command so the development server's request log shows `[secure link removed]` instead of the token path or signature.

### Production web servers (Apache / Nginx)

Exclude or redact these paths in access logs: `/invitations/*`, `/apply/verify/*`, `/apply/status/*` and `/account/email/confirm/*`.

**Apache (XAMPP `httpd.conf` or a vhost):**

```apache
SetEnvIf Request_URI "^/invitations/" memberflow_secret
SetEnvIf Request_URI "^/apply/(verify|status)/" memberflow_secret
SetEnvIf Request_URI "^/account/email/confirm/" memberflow_secret
CustomLog "logs/access.log" combined env=!memberflow_secret
```

**Nginx:**

```nginx
location ~ ^/(invitations|apply/verify|apply/status|account/email/confirm)/ {
    access_log off;
    try_files $uri /index.php?$query_string;
}
```

Also make sure error logs, reverse proxies, CDNs and monitoring tools do not record full URLs for these paths.

## 6. What needs external credentials

| Feature | Without credentials |
|---|---|
| Gmail delivery | Works in log mode: emails are tracked as *logged* (not delivered). Real delivery needs the App Password in `.env` (configured and verified live). |
| Google Maps / OpenWeather | Both keys are configured in `.env` and were verified live (R7, R8). Without a key, or with a rejected key, the map is hidden with an "unavailable" message, coordinates can still be typed, and weather shows an honest "unavailable" message. See `maps-and-weather.md`. |

# MemberFlow — Same-Wi-Fi (LAN) demonstration and restore steps

**Status (Nov 24, 2026):** the owner has been running MemberFlow on the LAN for phone testing:
- `APP_URL` is `http://192.168.100.4:8000`, and `php artisan serve --host=0.0.0.0 --port=8000` is used;
- `APP_DEBUG=false`;
- **Google Maps was verified live on this LAN address** (the key accepts it).

No firewall rule or network setting was changed by the developer. A read-only check found **no** firewall rule named "MemberFlow …", and the Wi-Fi profile is **Public**. Every step below that changes the system needs the owner's explicit approval, and some must be run by the owner in an **Administrator** PowerShell.

Use LAN mode only after the local demo (on this PC, `http://127.0.0.1:8000`) works. It is plain HTTP, so passwords and links travel unencrypted across the Wi-Fi. Use **fictional records and throwaway passwords only**.

## 1. What was found (read-only)

| Item | Value | Note |
|---|---|---|
| Wi-Fi IPv4 address | `192.168.100.4` (/24) | Assigned by the router (DHCP). **Re-check on demo day** with `ipconfig` |
| Wi-Fi network profile | **Public** | Windows applies the stricter "Public" firewall profile on this network |
| Firewall rules named "MemberFlow …" | none | If phones cannot connect, use Step 2 |
| `APP_DEBUG` | `false` | Keep it `false` while other devices can reach the app |
| Google Maps on `http://192.168.100.4:8000` | works | Verified in Chrome, Nov 24, 2026 |

## 2. Proposed configuration

### Step 1: network profile (owner decision)
The proposed firewall rule applies to the **Private** profile only, so either:
- **Recommended:** if this Wi-Fi is a trusted home or classroom network, set it to Private: *Settings → Network & internet → Wi-Fi → (network name) → Network profile type → Private*. Then use the Private-only rule below.
- If the network must stay Public, the rule would need `-Profile Public`. It stays limited to the local subnet by `-RemoteAddress LocalSubnet`. This is less safe on shared networks.

### Step 2: firewall rule (Administrator PowerShell, owner approval required)
```powershell
New-NetFirewallRule -DisplayName "MemberFlow Demo 8000" -Direction Inbound -Protocol TCP -LocalPort 8000 `
  -Profile Private -RemoteAddress LocalSubnet -Action Allow
```
This allows only devices on the same subnet to reach port 8000. Nothing is opened to the internet (the home router still blocks inbound traffic).

### Step 3: `.env` (owner edits the file)
```
APP_URL=http://192.168.100.4:8000
APP_DEBUG=false
```
Then:
```
php artisan config:clear
```
`APP_URL` makes email links (invitations, verification) point to the LAN address. `APP_DEBUG=false` shows the friendly error pages instead of stack traces.

### Step 4: start the server on all interfaces, and restart the queue worker
```
php artisan serve --host=0.0.0.0 --port=8000
php artisan queue:work            # only with QUEUE_CONNECTION=database; not needed with sync. Restart it after .env changes
```

### Step 5: Google Maps (only if a Maps key is configured)
Add `http://192.168.100.4:8000/*` to the key's HTTP-referrer restrictions in Google Cloud. Note that "Use my current location" does **not** work over plain HTTP on another device; tapping the map and typing coordinates still work.

### Step 6: test device
1. Connect the phone or laptop to the **same Wi-Fi**, and turn off mobile data so it can't use another network.
2. Open `http://192.168.100.4:8000/apply` (public form) or `http://192.168.100.4:8000/login`.
3. If it doesn't load, check that the server shows "Server running on [http://0.0.0.0:8000]", the IP is still `192.168.100.4`, the firewall rule exists, and the network profile matches the rule.
4. Emails opened on the phone (Gmail app) now contain `http://192.168.100.4:8000/...` links that work on that network.

### Accounts during the demo
- Use the fictional demo data (`php artisan db:seed --class=DemoDataSeeder` if wanted), and give fictional members Gmail "+" alias addresses of a test inbox you control (for example `yourtest+member1@gmail.com`) so their activation emails arrive on the phone.
- Before the demo, temporarily change the administrator password (Profile) to a throwaway one, because it will travel over plain HTTP. Change it back afterwards.

## 3. Restore local-only mode after the demo

1. Stop `php artisan serve` (Ctrl+C) and start it again without `--host`: `php artisan serve`. It then listens on `127.0.0.1` only.
2. Remove the firewall rule (Administrator PowerShell):
   ```powershell
   Remove-NetFirewallRule -DisplayName "MemberFlow Demo 8000"
   ```
3. `.env`: `APP_URL=http://127.0.0.1:8000` (and `APP_DEBUG=true` only if you want debug pages for local development), then `php artisan config:clear`.
4. Restart `php artisan queue:work` if you use `QUEUE_CONNECTION=database` (not needed with `sync`).
5. If you changed the Wi-Fi profile to Private only for the demo, set it back to Public.
6. Change the administrator password back from the throwaway one.
7. Links emailed during the demo point to `192.168.100.4` and will no longer open on this PC. Resend invitations or verification emails if they are still needed.

## 4. Safety summary

| Risk | Mitigation |
|---|---|
| Unencrypted HTTP on Wi-Fi | Fictional data and throwaway passwords only; demo-only |
| Other people on the network | Rule limited to the local subnet and, preferably, the Private profile; removed right after |
| Debug information leak | `APP_DEBUG=false` during the demo |
| Stale links | Restart the worker after changing `APP_URL`; resend links after restoring |

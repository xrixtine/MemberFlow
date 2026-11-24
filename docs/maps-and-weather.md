# MemberFlow — Google Maps and OpenWeather

Both integrations are **optional**. Without keys, everything still works:
- **Without a Google Maps key:** forms show "map unavailable" and take latitude and longitude typed by hand.
- **Without an OpenWeather key:** weather panels show "Weather is unavailable" and never show made-up data.

Enter keys only in `.env`, never in source code or chat. Run `php artisan config:clear` after changing them.

## Where they appear

| Feature | Pages |
|---|---|
| Map location picker | Public application (`/apply`), admin **Register member**, admin **Edit member** |
| Read-only map + "Open in Google Maps" link | Admin member profile, when the member has coordinates; **My membership** (member portal), showing the member's **own** location only while location consent is on file |
| Current weather | Admin and member dashboards (office location: `MEMBERFLOW_LOCATION_LABEL`, default Manila 14.5995, 120.9842), admin member profiles with coordinates, and **My membership** for the member's own consented location |

Members never see another member's location: the portal reads only the signed-in member's own record, and the admin member pages (with their maps) return 403 for members.

## Live verification (R7, 2026-11-24)
- **Google Maps JavaScript API:** loaded and rendered in Chrome with no provider errors, so the API is enabled, billing is active (no "for development purposes only" watermark) and the key accepts the local origin. Verified: click-to-place, drag, manual entry, clear, "use my current location", the consent prompt, the server refusing coordinates without consent, and read-only maps on the admin profile and in the member portal, at 375 px and 1440 px.
- **OpenWeather Current Weather (free tier):** returned HTTP 200 with live data. The displayed values match the provider's response, the observation time is the provider's `dt`, a second request is served from the cache, and the key never appears in pages or JSON.

The public application page does **not** show weather. That keeps the server-side weather endpoint closed to anonymous visitors, so they can't use up the OpenWeather quota, and applying never depends on it.

## Location privacy and consent
- **Consent is required.** The server rejects coordinates unless the consent checkbox is ticked. On the public form, the applicant ticks it; on admin forms, the administrator confirms that the member agreed.
- **Consent is recorded.** It is stored as `members.location_consent_at`, and clearing the coordinates also clears it.
- **The service layer checks it too.** If coordinates ever arrive without consent, `MembershipService` discards them.
- **No coordinates in logs.** Weather request logs record only the failure reason.
- **Rounded before sharing.** Coordinates are rounded to 2 decimals (about 1 km) before being sent to OpenWeather, so the provider never receives a member's exact location.

## Google Maps setup (browser key)
1. Go to https://console.cloud.google.com/, create or select a project, and **enable billing**. Maps requires it; the monthly free usage covers a school demo.
2. Go to **APIs & Services → Library** and enable **Maps JavaScript API**.
3. Go to **APIs & Services → Credentials → Create credentials → API key**.
4. **Restrict the key.** This step is required, because a browser key is visible in the page source.
   - **Application restrictions → Websites (HTTP referrers):** add `http://127.0.0.1:8000/*` and `http://localhost:8000/*`. Add the LAN or HTTPS origin later if you use one.
   - **API restrictions → Restrict key → Maps JavaScript API** only.
5. In `.env`, set `GOOGLE_MAPS_KEY=...`. Optionally set `GOOGLE_MAPS_MAP_ID` to your own Map ID; the default is Google's `DEMO_MAP_ID`, which is fine for development.
6. Run `php artisan config:clear` and reload a form. If the key is wrong or not authorised for the origin, the page says the map is unavailable and manual entry keeps working.

## OpenWeather setup (server key)
1. Create a free account at https://openweathermap.org/ and open **API keys** (https://home.openweathermap.org/api_keys).
2. Copy the default key, or create one named "MemberFlow". **New keys can take up to about 2 hours to activate.** Until then the API answers 401, and MemberFlow shows "rejected the API key".
3. In `.env`, set `OPENWEATHER_KEY=...` and run `php artisan config:clear`.

MemberFlow uses the free **Current Weather Data** endpoint (`/data/2.5/weather`, metric units).

## How weather requests are protected
- **Server only:** the browser calls `GET /weather?lat=..&lon=..`, which requires sign-in. Laravel then calls OpenWeather with the key. The key is never in HTML, JavaScript or JSON responses.
- **Limits:**
  - 30 requests per minute per user on the endpoint;
  - a global budget of 50 upstream calls per minute, under the free tier's 60;
  - results cached for 10 minutes per rounded location;
  - errors cached for 60 seconds, so an outage isn't hammered.
- **Timeouts:** 3 s to connect, 5 s in total.
- **Error handling:** a timeout, an HTTP error, a 401 for a bad key, a 429 rate limit or a malformed response all return `503` with an "unavailable" message. The logs record the reason only, without the key, the URL or any coordinates.

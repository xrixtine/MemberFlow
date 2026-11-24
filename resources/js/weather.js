/**
 * Weather widgets: fetch GET /weather (server-side OpenWeather proxy) and render
 * with textContent only. Errors show an "unavailable" message — nothing is invented.
 */

const FALLBACK = 'Weather is unavailable right now.';

function show(root, selector, value) {
    const el = root.querySelector(`[data-w="${selector}"]`);
    if (el) el.textContent = value ?? '—';
}

async function loadWeather(root) {
    const loading = root.querySelector('[data-weather-loading]');
    const body = root.querySelector('[data-weather-body]');
    const error = root.querySelector('[data-weather-error]');
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 12000);

    const fail = (message) => {
        loading.hidden = true;
        body.hidden = true;
        error.hidden = false;
        error.textContent = message || FALLBACK;
        root.setAttribute('aria-busy', 'false');
    };

    try {
        const response = await fetch(root.dataset.url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal,
        });
        const payload = await response.json().catch(() => null);

        if (response.status === 429) return fail('Too many weather requests. Please try again in a minute.');
        if (!payload || payload.status !== 'ok' || !payload.data) return fail(payload?.message);

        const d = payload.data;
        show(root, 'temperature', d.temperature?.toFixed?.(1) ?? d.temperature);
        show(root, 'description', d.description);
        show(root, 'feels_like', d.feels_like !== null ? `${d.feels_like} °C` : null);
        show(root, 'humidity', d.humidity !== null ? `${d.humidity}%` : null);
        show(root, 'wind_speed', d.wind_speed !== null ? `${d.wind_speed} m/s` : null);
        show(root, 'clouds', d.clouds !== null ? `${d.clouds}%` : null);
        show(root, 'sun', d.sunrise && d.sunset ? `${d.sunrise} / ${d.sunset}` : null);
        show(root, 'observed_label', d.observed_label);
        show(root, 'place', d.location ? `${d.location} · ` : '');

        loading.hidden = true;
        error.hidden = true;
        body.hidden = false;
        root.setAttribute('aria-busy', 'false');
    } catch {
        fail('The weather could not be loaded. Please try again later.');
    } finally {
        clearTimeout(timer);
    }
}

document.querySelectorAll('[data-weather]').forEach(loadWeather);

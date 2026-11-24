/**
 * Google Maps location picker / viewer (progressive enhancement).
 *
 * - The Maps script is loaded only when the server rendered a data-maps-key
 *   (i.e. GOOGLE_MAPS_KEY is configured) and only on pages that need a map.
 * - Any failure (network, blocked script, invalid or restricted key, timeout)
 *   shows an "unavailable" message; manual latitude/longitude entry keeps working.
 * - The map only fills the two inputs. The server validates the values and
 *   requires location consent (see ValidatesLocation).
 */

const UNAVAILABLE = 'The map could not be loaded. You can still enter the latitude and longitude manually.';
const AUTH_FAILED = 'The map is unavailable (the Google Maps key was rejected). You can still enter the coordinates manually.';
let mapsPromise = null;

function loadGoogleMaps(key) {
    if (window.google?.maps?.importLibrary) return Promise.resolve();
    if (mapsPromise) return mapsPromise;

    mapsPromise = new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('timeout')), 15000);
        window.__memberflowMapsReady = () => {
            clearTimeout(timer);
            resolve();
        };
        const script = document.createElement('script');
        script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key)
            + '&loading=async&v=weekly&callback=__memberflowMapsReady';
        script.async = true;
        script.onerror = () => {
            clearTimeout(timer);
            reject(new Error('load'));
        };
        document.head.appendChild(script);
    });

    return mapsPromise;
}

// Google calls this global when the key is invalid or not authorised for this origin.
window.gm_authFailure = () => {
    document.querySelectorAll('[data-location-picker] [data-map], [data-location-view] [data-map]').forEach((el) => {
        el.closest('.overflow-hidden')?.setAttribute('hidden', '');
    });
    document.querySelectorAll('[data-map-status]').forEach((el) => {
        el.textContent = AUTH_FAILED;
    });
};

function parseCoordinate(value, limit) {
    const text = String(value ?? '').trim();
    if (!/^-?\d{1,3}(\.\d+)?$/.test(text)) return null;
    const number = Number(text);
    return Math.abs(number) <= limit ? number : null;
}

async function createMap(container, center, zoom, mapId) {
    const { Map } = await google.maps.importLibrary('maps');
    const { AdvancedMarkerElement } = await google.maps.importLibrary('marker');
    const map = new Map(container, {
        center, zoom, mapId: mapId || 'DEMO_MAP_ID',
        streetViewControl: false, mapTypeControl: false, fullscreenControl: true, clickableIcons: false,
    });
    return { map, AdvancedMarkerElement };
}

async function initPicker(root) {
    const latInput = root.querySelector('[data-location-lat]');
    const lngInput = root.querySelector('[data-location-lng]');
    const status = root.querySelector('[data-map-status]');
    const consent = root.querySelector('[data-location-consent]');
    const consentBox = root.querySelector('[data-location-consent-box]');
    const clearButton = root.querySelector('[data-location-clear]');
    const currentButton = root.querySelector('[data-location-current]');
    const mapEl = root.querySelector('[data-map]');
    let marker = null;
    let map = null;

    const say = (text) => { if (status) status.textContent = text; };
    const highlightConsent = () => {
        const needed = latInput.value.trim() !== '' || lngInput.value.trim() !== '';
        consentBox?.classList.toggle('border-lime-deep', needed && !consent?.checked);
    };

    const setInputs = (lat, lng) => {
        latInput.value = lat.toFixed(7);
        lngInput.value = lng.toFixed(7);
        highlightConsent();
        say(`Location set to ${latInput.value}, ${lngInput.value}.` + (consent && !consent.checked ? ' Please tick the consent box below.' : ''));
    };

    const placeMarker = (position, Marker) => {
        if (!map) return;
        if (!marker) {
            marker = new Marker({ map, position, gmpDraggable: true, title: 'Selected location' });
            marker.addListener('dragend', () => {
                const p = marker.position;
                setInputs(typeof p.lat === 'function' ? p.lat() : p.lat, typeof p.lng === 'function' ? p.lng() : p.lng);
            });
        } else {
            marker.position = position;
            marker.map = map;
        }
    };

    clearButton?.addEventListener('click', () => {
        latInput.value = '';
        lngInput.value = '';
        if (marker) marker.map = null;
        highlightConsent();
        say('Location cleared.');
    });
    consent?.addEventListener('change', highlightConsent);

    // "Use my current location" works with or without the map (needs HTTPS or localhost).
    if (currentButton && 'geolocation' in navigator && window.isSecureContext) {
        currentButton.hidden = false;
        currentButton.addEventListener('click', () => {
            say('Finding your location…');
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    setInputs(pos.coords.latitude, pos.coords.longitude);
                    root.dispatchEvent(new CustomEvent('location:set', { detail: { lat: pos.coords.latitude, lng: pos.coords.longitude } }));
                },
                () => say('Your location could not be determined. Choose it on the map or type the coordinates.'),
                { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 },
            );
        });
    }

    if (!root.dataset.mapsKey || !mapEl) return;

    try {
        await loadGoogleMaps(root.dataset.mapsKey);
        const lat = parseCoordinate(latInput.value, 90);
        const lng = parseCoordinate(lngInput.value, 180);
        const hasPoint = lat !== null && lng !== null;
        const center = hasPoint ? { lat, lng } : { lat: Number(root.dataset.defaultLat), lng: Number(root.dataset.defaultLng) };

        const created = await createMap(mapEl, center, hasPoint ? 16 : 12, root.dataset.mapId);
        map = created.map;
        const Marker = created.AdvancedMarkerElement;
        if (hasPoint) placeMarker(center, Marker);
        say('Click or tap the map to place the marker, or drag it to adjust.');

        map.addListener('click', (event) => {
            if (!event.latLng) return;
            placeMarker(event.latLng, Marker);
            setInputs(event.latLng.lat(), event.latLng.lng());
        });

        root.addEventListener('location:set', (event) => {
            const position = { lat: event.detail.lat, lng: event.detail.lng };
            placeMarker(position, Marker);
            map.panTo(position);
        });

        const syncFromInputs = () => {
            const la = parseCoordinate(latInput.value, 90);
            const ln = parseCoordinate(lngInput.value, 180);
            if (la !== null && ln !== null) {
                placeMarker({ lat: la, lng: ln }, Marker);
                map.panTo({ lat: la, lng: ln });
            }
            highlightConsent();
        };
        latInput.addEventListener('change', syncFromInputs);
        lngInput.addEventListener('change', syncFromInputs);
    } catch {
        mapEl.closest('.overflow-hidden')?.setAttribute('hidden', '');
        say(UNAVAILABLE);
    }
}

async function initViewer(root) {
    const status = root.parentElement?.querySelector('[data-map-status]');
    const position = { lat: Number(root.dataset.lat), lng: Number(root.dataset.lng) };
    try {
        await loadGoogleMaps(root.dataset.mapsKey);
        const { map, AdvancedMarkerElement } = await createMap(root.querySelector('[data-map]'), position, 15, root.dataset.mapId);
        new AdvancedMarkerElement({ map, position, title: 'Member location' });
    } catch {
        root.setAttribute('hidden', '');
        if (status) status.textContent = 'The map could not be loaded. Use "Open in Google Maps" instead.';
    }
}

document.querySelectorAll('[data-location-picker]').forEach(initPicker);
document.querySelectorAll('[data-location-view]').forEach(initViewer);

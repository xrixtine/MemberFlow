<?php

namespace App\Support;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * HTTPS behind a shared host's reverse proxy (e.g. InfinityFree: nginx in front of Apache).
 *
 * The proxy terminates HTTPS and forwards plain HTTP, so without this Laravel would
 * generate http:// asset and form URLs (mixed content). Configured only from
 * config('memberflow.*'); with nothing set (local XAMPP) nothing changes.
 *
 *  - trusted_proxies: "*" or a comma-separated IP list. Trusts X-Forwarded-Proto and
 *    X-Forwarded-Port only; X-Forwarded-Host is never trusted (host-header forgery).
 *  - trusted_proxy_forwarded_for: also trust X-Forwarded-For (client IP for rate
 *    limits). Enable only when the proxy overwrites that header.
 *  - force_https: every generated URL uses https.
 */
final class HostingProxy
{
    public static function apply(): void
    {
        $proxies = trim((string) config('memberflow.trusted_proxies', ''));

        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));

            $headers = Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT;
            if (config('memberflow.trusted_proxy_forwarded_for')) {
                $headers |= Request::HEADER_X_FORWARDED_FOR;
            }
            TrustProxies::withHeaders($headers);
        }

        if (config('memberflow.force_https')) {
            URL::forceScheme('https');
        }
    }
}

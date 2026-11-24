<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for every web response.
 *
 *  - X-Frame-Options / frame-ancestors: no other site can frame MemberFlow (clickjacking).
 *  - X-Content-Type-Options: browsers must not guess content types.
 *  - Referrer-Policy: only the origin is sent to other sites. Pages that already set a
 *    stricter policy (NoReferrer middleware on token pages) keep theirs.
 *  - Permissions-Policy: geolocation only for this origin (map "use my location"); camera
 *    and microphone are never used.
 *  - The PHP version banner (X-Powered-By) is removed.
 *
 * A full Content-Security-Policy is not set because Google Maps loads scripts from
 * several Google origins; that is documented as a production hardening step.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('Content-Security-Policy', "frame-ancestors 'self'", false);
        $headers->set('Permissions-Policy', 'geolocation=(self), camera=(), microphone=()', false);
        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        $headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}

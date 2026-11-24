<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honeypot for public forms: a visually hidden field ("website") that people never
 * fill in. If it has a value, the submission is dropped and the bot receives the
 * normal success page, so it learns nothing. Nothing is stored or logged.
 */
class RejectHoneypot
{
    public const FIELD = 'website';

    public function handle(Request $request, Closure $next, string $redirectRoute): Response
    {
        if ($request->isMethod('POST') && filled($request->input(self::FIELD))) {
            return redirect()->route($redirectRoute)->with('application_submitted', true);
        }

        return $next($request);
    }
}

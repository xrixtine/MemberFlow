<?php

namespace App\Support;

/**
 * Absolute URLs for links that leave the current request (emails, CLI output).
 *
 * Laravel's absolute route() uses the URL generator's current root: the request's host
 * during a web request, or APP_URL as it was when a long-running process (queue worker)
 * started. Emailed links must not depend on either, so they are built from the
 * configured APP_URL plus the route's relative path.
 *
 * Queued emails go one step further: TrackedNotification records APP_URL when the email
 * is queued (in the web request, which always reads the current .env) and the worker
 * builds the links with that value via AppUrl::using(). A worker started before an
 * APP_URL change therefore still produces correct links (once it runs this code).
 */
final class AppUrl
{
    /** Base URL captured for the email being rendered (null = use config('app.url')). */
    private static ?string $base = null;

    /** Absolute URL for a named route, based on APP_URL. */
    public static function route(string $name, mixed $parameters = []): string
    {
        return self::to(route($name, $parameters, absolute: false));
    }

    /** Prefix a relative path (e.g. a relative signed URL) with APP_URL. */
    public static function to(string $relativePath): string
    {
        return rtrim(self::base(), '/').'/'.ltrim($relativePath, '/');
    }

    public static function base(): string
    {
        return self::$base ?? (string) config('app.url');
    }

    /**
     * Run $callback with links based on $base (when given), then restore the previous base.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function using(?string $base, callable $callback): mixed
    {
        $previous = self::$base;
        self::$base = filled($base) ? $base : $previous;

        try {
            return $callback();
        } finally {
            self::$base = $previous;
        }
    }
}

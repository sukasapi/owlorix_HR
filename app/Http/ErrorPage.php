<?php

namespace App\Http;

use Illuminate\Http\Request;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

/**
 * What an error page says and offers, in plain words, for both renderings: the Blade page for a full page load
 * (resources/views/errors) and the Inertia page errors/Show for a visit inside the app. Reads nothing from the
 * database, so it still works while MySQL is down.
 */
final class ErrorPage
{
    /** Statuses with their own copy; any other 4xx or 5xx uses the 4xx or 500 copy */
    private const OWN_COPY = [403, 404, 419, 429, 500, 503];

    /** Request errors that a reload or a short wait fixes, so they offer a reload like server errors do */
    private const RETRY = [419, 429];

    /**
     * @param  string|null  $copy  a key in lang/<locale>/errors.php, when the status alone is not specific enough
     * @return array{status: int, title: string, body: string, code: string, note: string|null, primary: array{label: string, href: string}, secondary: array{label: string, href: string}|null}
     */
    public static function for(int $status, Request $request, ?string $copy = null): array
    {
        $server = $status >= 500;
        $copy ??= match (true) {
            in_array($status, self::OWN_COPY, true) => (string) $status,
            $server => '500',
            default => '4xx',
        };

        $home = ['label' => __('errors.home'), 'href' => url('/')];
        $previous = self::previous($request);

        [$primary, $secondary] = $server || in_array($status, self::RETRY, true)
            ? [['label' => __('errors.reload'), 'href' => self::reloadUrl($request, $previous)], $home]
            : [$home, $previous !== null ? ['label' => __('errors.back'), 'href' => $previous] : null];

        return [
            'status' => $status,
            'title' => __("errors.{$copy}.title"),
            'body' => __("errors.{$copy}.body"),
            'code' => __('errors.code', ['status' => $status]),
            'note' => $server ? __('errors.desktop') : null,
            'primary' => $primary,
            'secondary' => $secondary,
        ];
    }

    /**
     * A visit inside the app (Inertia) gets errors/Show in place of the page instead of Inertia's HTML modal. Shared
     * props are left out because they read the database. In debug mode a server error keeps Laravel's stack trace.
     */
    public static function inertia(ExceptionResponse $response): ?ExceptionResponse
    {
        $request = $response->request;
        $status = $response->statusCode();
        $database = DatabaseUnavailable::causedBy($response->exception);

        if (! $request->headers->has('X-Inertia') || $request->is('api/*') || $status < 400) {
            return null;
        }

        if ($status >= 500 && ! $database && config('app.debug')) {
            return null;
        }

        // HandleInertiaRequests may have shared its props before the error; they would query the database again
        Inertia::flushShared();

        return $response->render('errors/Show', ['page' => self::for($status, $request, $database ? 'database' : null)]);
    }

    /** A GET is safe to repeat; after a form post, reload the page the form was on. */
    private static function reloadUrl(Request $request, ?string $previous): string
    {
        return $request->isMethod('GET') ? $request->fullUrl() : ($previous ?? url('/'));
    }

    /** The page the person came from, only when it is on this site and is not the failing page itself. */
    private static function previous(Request $request): ?string
    {
        $referer = $request->headers->get('referer');

        if (! is_string($referer) || parse_url($referer, PHP_URL_HOST) !== $request->getHost() || $referer === $request->fullUrl()) {
            return null;
        }

        return $referer;
    }
}

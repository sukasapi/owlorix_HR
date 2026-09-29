<?php

namespace App\Http;

use App\Modules\Attendance\Http\ApiError;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use PDOException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Answers 503 instead of a bare 500 while MySQL cannot be reached (refused, gone away). The desktop gets its usual
 * JSON error and keeps the events in its outbox; a browser gets a page that needs neither the database nor Vite.
 * The exception is still logged.
 */
final class DatabaseUnavailable
{
    public const RETRY_AFTER_SECONDS = 30;

    public static function causedBy(Throwable $e): bool
    {
        return ($e instanceof QueryException || $e instanceof PDOException) && (new LostConnectionDetector)->causedByLostConnection($e);
    }

    public static function render(Throwable $e, Request $request): ?Response
    {
        if (! self::causedBy($e)) {
            return null;
        }

        $headers = ['Retry-After' => self::RETRY_AFTER_SECONDS];

        if ($request->is('api/*') || $request->expectsJson()) {
            return ApiError::response(503, 'database_unavailable', __('errors.database_api'), $headers);
        }

        return response()->view('errors.page', ['page' => ErrorPage::for(503, $request, 'database')], 503, $headers);
    }
}

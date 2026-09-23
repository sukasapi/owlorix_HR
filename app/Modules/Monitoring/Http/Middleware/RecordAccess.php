<?php

namespace App\Modules\Monitoring\Http\Middleware;

use App\Modules\Monitoring\Enums\AccessEvent;
use App\Modules\Monitoring\Services\AccessRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Monitor aktivitas (docs/14 2.2), last in the web group so it sees the final status after route middleware answered.
 * At most one row per request: `forbidden` for 403 and 429 (signed in or not), then for signed-in people `download`,
 * `page_view` for full Inertia page loads, or `action` for POST, PUT, PATCH and DELETE.
 */
class RecordAccess
{
    /** File downloads, recorded by route name so their controllers stay untouched */
    public const DOWNLOADS = ['tasks.evidence', 'people.cv', 'reports.export', 'leave.attachment'];

    /**
     * Not recorded as actions: the web clock heartbeat (a poll, once a minute per open tab) and sign-in or sign-out,
     * which the auth events already record. Team today and Hari ini refresh with partial reloads, skipped below.
     */
    public const SKIPPED_ACTIONS = ['web-clock.heartbeat', 'sign-in.store', 'sign-out'];

    private const WRITES = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly AccessRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->record($request, $response);
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        $status = $response->getStatusCode();
        $userId = $request->user()?->getKey();
        $name = $request->route()?->getName();

        if ($status === 403 || $status === 429) {
            $this->recorder->record(AccessEvent::Forbidden, $request, $userId, ['status' => $status]);

            return;
        }

        if ($userId === null || $name === null) {
            return;
        }

        $method = $request->getMethod();
        $ok = $status >= 200 && $status < 300;

        if ($ok && $method === 'GET' && in_array($name, self::DOWNLOADS, true)) {
            $this->recorder->record(AccessEvent::Download, $request, $userId, ['status' => $status]);
        } elseif ($ok && $method === 'GET' && $this->isPageLoad($request, $response)) {
            $this->recorder->record(AccessEvent::PageView, $request, $userId, ['status' => $status]);
        } elseif (in_array($method, self::WRITES, true) && ! in_array($name, self::SKIPPED_ACTIONS, true)) {
            $this->recorder->record(AccessEvent::Action, $request, $userId, ['status' => $status]);
        }
    }

    /** A whole Inertia page: the first HTML load or a client visit, not a partial reload or a prefetch. */
    private function isPageLoad(Request $request, Response $response): bool
    {
        if ($request->headers->has(Header::PARTIAL_ONLY) || $request->headers->has(Header::PARTIAL_EXCEPT) || $request->header('Purpose') === 'prefetch') {
            return false;
        }

        if ($response->headers->has(Header::INERTIA)) {
            return true;
        }

        $content = method_exists($response, 'getOriginalContent') ? $response->getOriginalContent() : null;

        return $content instanceof View && array_key_exists('page', $content->getData());
    }
}

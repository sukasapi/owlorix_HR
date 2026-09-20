<?php

namespace App\Modules\Attendance\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Http\Middleware\AuthenticateDevice;
use App\Modules\Attendance\Http\Requests\SyncEventsRequest;
use App\Modules\Attendance\Services\DesktopState;
use App\Modules\Attendance\Services\EventIngestor;
use App\Modules\Attendance\Services\IngestResult;
use Illuminate\Http\JsonResponse;

/**
 * Accepted and duplicate ids can leave the PC outbox. Rejected events will never be accepted: the PC keeps them
 * aside with their code instead of resending them.
 */
class SyncEventsController extends Controller
{
    public function __invoke(SyncEventsRequest $request, EventIngestor $ingestor, DesktopState $state): JsonResponse
    {
        $user = $request->user();
        [$valid, $invalid] = $request->partitionEvents();

        $result = $valid !== []
            ? $ingestor->ingest($user, AuthenticateDevice::device($request), $valid)
            : new IngestResult([], []);

        $rejected = [...$invalid, ...$result->rejected];
        usort($rejected, fn (array $a, array $b) => $a['index'] <=> $b['index']);

        return response()->json([
            'accepted' => $result->accepted,
            'duplicates' => $result->duplicates,
            'rejected' => $rejected,
            ...$state->for($user),
        ]);
    }
}

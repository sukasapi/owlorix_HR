<?php

namespace App\Modules\Identity\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Http\Middleware\AuthenticateDevice;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Actions\IssueWebHandoff;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Desktop asks for a one-time web sign-in URL (docs/11-web-handoff.md). */
class DeviceWebHandoffController extends Controller
{
    public function __invoke(Request $request, IssueWebHandoff $issue): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $device = AuthenticateDevice::device($request);
        $result = $issue->handle($user, $device);

        return response()->json([
            'url' => $result['url'],
            'expires_at' => Time::iso($result['expires_at']),
        ]);
    }
}

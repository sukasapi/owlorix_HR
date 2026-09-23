<?php

namespace App\Modules\Identity\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Monitoring\Enums\AccessEvent;
use App\Modules\Monitoring\Services\AccessRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Revokes the token this request was made with. The shift is not touched: signing out of the app is not clocking out. */
class DeviceLogoutController extends Controller
{
    public function __invoke(Request $request, AccessRecorder $access): Response
    {
        $token = $request->user()->currentAccessToken();
        $token->delete();

        $access->record(AccessEvent::DeviceSignOut, $request, $request->user()->id, ['device_id' => $token->name]);

        return response()->noContent();
    }
}

<?php

namespace App\Modules\Identity\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Revokes the token this request was made with. The shift is not touched: signing out of the app is not clocking out. */
class DeviceLogoutController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}

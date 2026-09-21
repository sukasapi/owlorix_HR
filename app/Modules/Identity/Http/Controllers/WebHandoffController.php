<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\ConsumeWebHandoff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Browser lands here from the desktop "Mulai kerja" button. The token is single-use and short-lived.
 */
class WebHandoffController extends Controller
{
    public function __invoke(Request $request, string $token, ConsumeWebHandoff $consume): RedirectResponse
    {
        $user = $consume->handle($request, $token);

        if ($user === null) {
            return redirect()
                ->route('sign-in')
                ->withErrors(['username' => __('auth.handoff_invalid')]);
        }

        if ($user->must_change_password) {
            return redirect()->route('password.edit');
        }

        return redirect()->intended(route('my-day'));
    }
}

<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Services\WebClock;
use App\Modules\Attendance\Services\WebDevice;
use App\Modules\Identity\Auth\LoginThrottle;
use App\Modules\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SignInController extends Controller
{
    public function create(WebClock $webClock): Response
    {
        return Inertia::render('auth/SignIn', [
            // 3.11.8: the page says where to clock in, so it follows the setting
            'web_clock_in_enabled' => $webClock->enabled(),
        ]);
    }

    public function store(Request $request, LoginThrottle $throttle): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $username = trim($credentials['username']);
        $wait = $throttle->secondsUntilAllowed($username, $request->ip());

        if ($wait > 0) {
            throw ValidationException::withMessages([
                'username' => __('auth.throttle_minutes', ['minutes' => (int) ceil($wait / 60)]),
            ]);
        }

        $user = User::query()->where('username', $username)->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $throttle->recordFailure($username, $request->ip());

            throw ValidationException::withMessages(['username' => __('auth.failed')]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['username' => __('auth.inactive')]);
        }

        $throttle->clear($username);

        Auth::login($user);
        $request->session()->regenerate();
        WebDevice::rememberSignIn($request);

        return redirect()->intended(route('my-day'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('sign-in');
    }
}

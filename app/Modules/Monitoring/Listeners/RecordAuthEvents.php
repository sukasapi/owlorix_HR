<?php

namespace App\Modules\Monitoring\Listeners;

use App\Modules\Identity\Auth\AccountLookup;
use App\Modules\Identity\Models\User;
use App\Modules\Monitoring\Enums\AccessEvent;
use App\Modules\Monitoring\Services\AccessRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

/**
 * Web sign-in, failed sign-in, sign-out, and lockout (docs/14 2.2). SignInController dispatches Failed and Lockout
 * itself because it checks passwords without Auth::attempt(). The desktop API writes its own rows.
 *
 * Failed attempts store the account's username, so tries by email and by username count together in "Perlu dicek".
 * Text that matches no account is stored masked (AccountLookup::logName), since it may be a password.
 */
class RecordAuthEvents
{
    public function __construct(
        private readonly AccessRecorder $recorder,
        private readonly Request $request,
    ) {}

    public function login(Login $event): void
    {
        // Imposter start and stop swap the signed-in person; the audit log and the action row already cover them
        if ($event->guard !== 'web' || $this->request->routeIs('imposter.*')) {
            return;
        }

        $this->recorder->record(AccessEvent::SignIn, $this->request, $event->user->getAuthIdentifier());
    }

    public function failed(Failed $event): void
    {
        $identifier = trim((string) ($event->credentials['username'] ?? ''));
        $user = $event->user instanceof User ? $event->user : null;

        $this->recorder->record(AccessEvent::SignInFailed, $this->request, $event->user?->getAuthIdentifier(), [
            'username' => $user !== null || $identifier !== '' ? AccountLookup::logName($identifier, $user) : null,
        ]);
    }

    public function logout(Logout $event): void
    {
        if ($event->guard !== 'web' || $event->user === null || $this->request->routeIs('imposter.*')) {
            return;
        }

        $this->recorder->record(AccessEvent::SignOut, $this->request, $event->user->getAuthIdentifier());
    }

    public function lockout(Lockout $event): void
    {
        $identifier = trim((string) $event->request->input('username', ''));
        $user = $identifier !== '' ? AccountLookup::find($identifier) : null;

        $this->recorder->record(AccessEvent::LockedOut, $event->request, $user?->getKey(), [
            'username' => $user !== null || $identifier !== '' ? AccountLookup::logName($identifier, $user) : null,
        ]);
    }
}

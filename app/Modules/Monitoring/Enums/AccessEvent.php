<?php

namespace App\Modules\Monitoring\Enums;

/** Events in access_logs (docs/14 2.1). */
enum AccessEvent: string
{
    case SignIn = 'sign_in';
    case SignInFailed = 'sign_in_failed';
    case SignOut = 'sign_out';
    case LockedOut = 'locked_out';
    case DeviceSignIn = 'device_sign_in';
    case DeviceSignInFailed = 'device_sign_in_failed';
    case DeviceSignOut = 'device_sign_out';
    case PageView = 'page_view';
    case Action = 'action';
    case Forbidden = 'forbidden';
    case Download = 'download';

    /** @return list<string> Events that count as a failed sign-in for "Perlu dicek" */
    public static function failedSignIns(): array
    {
        return [self::SignInFailed->value, self::DeviceSignInFailed->value, self::LockedOut->value];
    }
}

<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Navigation\Navigation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan: one page that lists every admin page the person can open, grouped by section (docs/desainUI_v2,
 * layout A). It replaces three menu groups; the pages themselves keep their own routes and permissions.
 */
class SettingsHubController extends Controller
{
    public function __invoke(Request $request, Navigation $navigation): Response
    {
        $sections = $navigation->settingsFor($request->user());

        abort_if($sections === [], 403);

        return Inertia::render('settings/Index', ['sections' => $sections]);
    }
}

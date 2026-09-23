<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Branding\Branding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The uploaded logo, public because the sign-in page and the favicon need it before anyone signs in.
 * Without an upload it redirects to the bundled Owlorix logo.
 */
class BrandLogoController extends Controller
{
    public function __invoke(Branding $branding): StreamedResponse|RedirectResponse
    {
        $path = $branding->logoPath();

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return redirect(Branding::DEFAULT_LOGO);
        }

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=604800',
            'Content-Disposition' => 'inline',
        ]);
    }
}

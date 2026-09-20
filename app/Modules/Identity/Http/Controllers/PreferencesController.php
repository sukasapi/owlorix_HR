<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreferencesController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['sometimes', Rule::in(['id', 'en'])],
            'theme' => ['sometimes', Rule::in(['system', 'light', 'dark'])],
        ]);

        $request->user()->update($validated);

        return back();
    }
}

<?php

use Illuminate\Support\Facades\Route;

// Panduan (docs/13 section 7): the user guide, its search, flows, and glossary. The content ships with the page, so
// the route only needs a signed-in person; pages are filtered by permission in the browser.
Route::inertia('/panduan', 'guide/Index')
    ->middleware(['auth', 'password.changed'])
    ->name('guide.index');

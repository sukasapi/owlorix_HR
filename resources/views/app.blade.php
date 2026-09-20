<!DOCTYPE html>
@php
    $theme = auth()->user()?->theme ?? 'system';
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-pref="{{ $theme }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title inertia>{{ config('app.name') }}</title>
        <link rel="icon" type="image/png" href="/owlorix-logo.png">
        <script>
            // Apply the theme before first paint so dark rooms do not get a white flash.
            (function () {
                var pref = document.documentElement.dataset.themePref;
                var dark = pref === 'dark' || (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
            })();
        </script>
        @routes
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>

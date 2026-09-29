{{-- Shown when MySQL cannot be reached, so it reads nothing from the database and loads no Vite assets. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>{{ __('errors.database_title') }} · {{ config('app.name') }}</title>
        <style>
            :root {
                --paper: #FBFAF7;
                --ink: #1A1A2E;
                --muted: #55607A;
                --line: #E4E1D8;
                --action: #1E4461;
                --action-text: #FFFFFF;
                --gold: #C99A33;
                --focus: #1E4461;
            }
            @media (prefers-color-scheme: dark) {
                :root {
                    --paper: #10121F;
                    --ink: #EDEFF5;
                    --muted: #9AA3B8;
                    --line: #2A2D45;
                    --action: #5BA6B4;
                    --action-text: #10121F;
                    --focus: #5BA6B4;
                }
            }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                padding: 24px 16px;
                background: var(--paper);
                color: var(--ink);
                font: 16px/1.55 "Instrument Sans", system-ui, -apple-system, "Segoe UI", sans-serif;
            }
            main { max-width: 30rem; }
            .eyes { display: flex; gap: 10px; margin-bottom: 20px; }
            h1 { margin: 0 0 12px; font-size: 1.5rem; line-height: 1.25; }
            p { margin: 0 0 12px; }
            .note { color: var(--muted); font-size: 0.9375rem; padding-top: 12px; border-top: 1px solid var(--line); margin-top: 20px; }
            a {
                display: inline-flex;
                align-items: center;
                min-height: 44px;
                padding: 0 18px;
                margin-top: 8px;
                border-radius: 8px;
                background: var(--action);
                color: var(--action-text);
                font-weight: 600;
                text-decoration: none;
            }
            a:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
        </style>
    </head>
    <body>
        <main>
            {{-- Half-closed owl eyes: the same "quiet" state the board uses for an idle PC --}}
            <div class="eyes" aria-hidden="true">
                @foreach ([0, 1] as $eye)
                    <svg width="28" height="28" viewBox="0 0 28 28">
                        <circle cx="14" cy="14" r="12" fill="var(--gold)" />
                        <rect x="0" y="0" width="28" height="14" fill="var(--paper)" />
                        <line x1="2" y1="14" x2="26" y2="14" stroke="var(--ink)" stroke-width="2" stroke-linecap="round" />
                    </svg>
                @endforeach
            </div>
            <h1>{{ __('errors.database_title') }}</h1>
            <p>{{ __('errors.database_body') }}</p>
            <a href="{{ $retryUrl }}">{{ __('errors.database_reload') }}</a>
            <p class="note">{{ __('errors.database_desktop') }}</p>
        </main>
    </body>
</html>

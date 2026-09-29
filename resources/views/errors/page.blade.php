{{--
    The error page for a full page load, with $page from App\Http\ErrorPage. It may be shown while MySQL is down, so it
    reads nothing from the database and loads no Vite assets: the colors below mirror resources/css/app.css, and the
    eyes are OwlEyes in the half-closed state. A visit inside the app gets the same content from pages/errors/Show.tsx.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="robots" content="noindex">
        <title>{{ $page['title'] }} · {{ config('app.name') }}</title>
        <style>
            :root {
                --paper: #FBFAF7;
                --surface: #FFFFFF;
                --panel: #DCEEEE;
                --ink: #1A1A2E;
                --heading: #1E4461;
                --muted: #55607A;
                --line: #E4E1D8;
                --line-strong: #CFCABC;
                --primary-bg: #1E4461;
                --primary-fg: #FFFFFF;
                --focus: #1E4461;
                --eye-socket: #DCEEEE;
                --eye-brow: #1E4461;
                --eye-lid: #1E4461;
                --eye-outline: #1A1A2E;
            }
            @media (prefers-color-scheme: dark) {
                :root {
                    --paper: #10121F;
                    --surface: #1A1A2E;
                    --panel: #1B2B45;
                    --ink: #EDEFF5;
                    --heading: #EDEFF5;
                    --muted: #9AA3B8;
                    --line: #2A2D45;
                    --line-strong: #3A3E5C;
                    --primary-bg: #5BA6B4;
                    --primary-fg: #10121F;
                    --focus: #5BA6B4;
                    --eye-brow: #5BA6B4;
                    --eye-lid: #5BA6B4;
                    --eye-outline: #10121F;
                }
            }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100dvh;
                display: grid;
                grid-template-columns: minmax(0, 34rem);
                justify-content: center;
                align-content: center;
                padding: 24px 16px;
                background: var(--paper);
                color: var(--ink);
                font: 16px/1.55 "Instrument Sans", system-ui, -apple-system, "Segoe UI", sans-serif;
            }
            .panel { background: var(--panel); border-radius: 24px 24px 8px 8px; padding: 28px 24px 24px; }
            .eyes { display: block; margin-bottom: 24px; }
            h1 { margin: 0 0 10px; color: var(--heading); font-size: clamp(1.625rem, 5vw, 2.125rem); line-height: 1.15; letter-spacing: -0.01em; }
            .body { margin: 0; max-width: 42ch; }
            .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 24px; }
            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 44px;
                padding: 8px 18px;
                border-radius: 6px;
                text-align: center;
                line-height: 1.3;
                border: 1px solid transparent;
                font-weight: 600;
                font-size: 15px;
                text-decoration: none;
            }
            .btn-primary { background: var(--primary-bg); color: var(--primary-fg); }
            .btn-secondary { background: var(--surface); color: var(--ink); border-color: var(--line-strong); }
            .btn:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
            .note { margin: 20px 4px 0; color: var(--muted); font-size: 0.9375rem; }
            .code { margin: 12px 4px 0; color: var(--muted); font-size: 0.8125rem; }
            @media (max-width: 420px) {
                .panel { padding: 24px 18px 20px; }
                .btn { flex: 1 1 100%; }
            }
        </style>
    </head>
    <body>
        <main>
            <div class="panel">
                <svg class="eyes" width="88" height="51" viewBox="-4 -4 108 58" aria-hidden="true">
                    <defs>
                        <clipPath id="lid-left"><circle cx="28" cy="30" r="13" /></clipPath>
                        <clipPath id="lid-right"><circle cx="72" cy="30" r="13" /></clipPath>
                    </defs>
                    @foreach (['left' => 28, 'right' => 72] as $side => $cx)
                        <circle cx="{{ $cx }}" cy="30" r="14" fill="var(--eye-socket)" stroke="var(--eye-outline)" stroke-width="2.2" />
                        <circle cx="{{ $cx }}" cy="30" r="8.6" fill="#C99A33" stroke="var(--eye-outline)" stroke-width="2" />
                        <circle cx="{{ $cx + 2 }}" cy="31" r="3.9" fill="#1A1A2E" />
                        <rect x="{{ $cx - 15 }}" y="15" width="30" height="15.5" fill="var(--eye-lid)" clip-path="url(#lid-{{ $side }})" />
                        <path d="M{{ $cx - 13 }} 30.5 L{{ $cx + 13 }} 30.5" stroke="var(--eye-outline)" stroke-width="2" />
                        <path d="{{ $side === 'left' ? 'M5 9 Q25 3 49 21' : 'M95 9 Q75 3 51 21' }}" fill="none" stroke="var(--eye-brow)" stroke-width="6.5" stroke-linecap="round" />
                    @endforeach
                </svg>
                <h1>{{ $page['title'] }}</h1>
                <p class="body">{{ $page['body'] }}</p>
                <div class="actions">
                    <a class="btn btn-primary" href="{{ $page['primary']['href'] }}">{{ $page['primary']['label'] }}</a>
                    @if ($page['secondary'])
                        <a class="btn btn-secondary" href="{{ $page['secondary']['href'] }}">{{ $page['secondary']['label'] }}</a>
                    @endif
                </div>
            </div>
            @if ($page['note'])
                <p class="note">{{ $page['note'] }}</p>
            @endif
            <p class="code">{{ $page['code'] }}</p>
        </main>
    </body>
</html>

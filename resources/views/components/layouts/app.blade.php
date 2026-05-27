<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Event Lead Capture' }}</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0a1f44">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Lead Capture">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="/assets/eric-event-roi-logo.png">
    <style>
        :root {
            --ink: #0a1f44;
            --body: #4d5d73;
            --muted: #7d8da3;
            --line: rgba(10, 31, 68, 0.14);
            --paper: #fbf7f1;
            --surface: #ffffff;
            --accent: #e8450a;
            --accent-dark: #bd3506;
            --green: #15803d;
            --amber: #a16207;
            --red: #b91c1c;
            --shadow: 0 10px 30px rgba(10, 31, 68, 0.08);
        }

        * { box-sizing: border-box; }
        html {
            min-height: 100%;
            background: var(--paper);
            max-width: 100%;
            overflow-x: hidden;
        }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                linear-gradient(rgba(10, 31, 68, 0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(10, 31, 68, 0.035) 1px, transparent 1px),
                var(--paper);
            background-size: 28px 28px;
            font-size: 15px;
            line-height: 1.5;
            min-height: 100vh;
            min-height: 100dvh;
            padding-bottom: env(safe-area-inset-bottom);
            max-width: 100%;
            overflow-x: hidden;
        }

        a { color: inherit; }
        .topbar {
            background: var(--ink);
            color: white;
            padding: calc(14px + env(safe-area-inset-top)) 16px 14px;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 4px 16px rgba(10, 31, 68, 0.22);
        }
        .topbar-inner {
            max-width: 1120px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            text-decoration: none;
            font-weight: 800;
        }
        .brand-mark {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            display: block;
            flex: 0 0 auto;
            object-fit: cover;
            box-shadow: 0 0 0 1px rgba(255,255,255,0.22);
        }
        .brand span:last-child {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .nav {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .nav a,
        .nav button {
            color: rgba(255,255,255,0.82);
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 6px;
            padding: 8px 10px;
            text-decoration: none;
            font: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        main {
            max-width: 1120px;
            margin: 0 auto;
            padding: 22px 16px 48px;
        }
        .hero-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 18px;
        }
        .hero-row > * {
            min-width: 0;
        }
        .hero-actions {
            justify-content: flex-end;
            align-items: stretch;
        }
        .hero-actions form {
            display: flex;
        }
        h1 {
            margin: 0;
            font-size: clamp(26px, 5vw, 42px);
            line-height: 1.08;
            letter-spacing: 0;
        }
        .subhead {
            margin: 8px 0 0;
            color: var(--body);
            max-width: 680px;
        }
        .panel,
        .item-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: var(--shadow);
        }
        .panel { padding: 18px; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 14px;
        }
        .stack { display: grid; gap: 14px; }
        .review-layout {
            display: grid;
            grid-template-columns: minmax(360px, 1.08fr) minmax(0, 0.92fr);
            gap: 16px;
            align-items: start;
        }
        .review-layout > *,
        .review-media,
        .review-panel {
            min-width: 0;
        }
        .row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .item-card {
            padding: 16px;
            display: grid;
            gap: 10px;
        }
        .item-title {
            margin: 0;
            font-size: 17px;
            line-height: 1.25;
        }
        .event-card,
        .capture-card {
            color: inherit;
            text-decoration: none;
            transition: border-color 160ms ease, box-shadow 160ms ease, transform 160ms ease;
        }
        .event-card:hover,
        .capture-card:hover {
            border-color: rgba(232, 69, 10, 0.42);
            box-shadow: 0 14px 34px rgba(10, 31, 68, 0.12);
            transform: translateY(-1px);
        }
        .meta {
            color: var(--muted);
            font-size: 13px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            border-radius: 999px;
            padding: 4px 9px;
            background: rgba(10, 31, 68, 0.08);
            color: var(--ink);
            font-size: 12px;
            font-weight: 800;
        }
        .badge.synced { background: rgba(21, 128, 61, 0.12); color: var(--green); }
        .badge.failed { background: rgba(185, 28, 28, 0.12); color: var(--red); }
        .badge.review { background: rgba(232, 69, 10, 0.12); color: var(--accent-dark); }
        .insight-list {
            display: grid;
            gap: 8px;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .insight-list li {
            border: 1px solid rgba(10, 31, 68, 0.1);
            border-radius: 6px;
            background: rgba(10, 31, 68, 0.035);
            padding: 9px 10px;
            color: var(--body);
            font-size: 13px;
            overflow-wrap: anywhere;
        }
        .insight-list strong {
            display: block;
            color: var(--ink);
            font-size: 11px;
            letter-spacing: 0.04em;
            margin-bottom: 2px;
            text-transform: uppercase;
        }

        form { margin: 0; }
        label {
            display: block;
            color: var(--ink);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        input,
        select,
        textarea {
            width: 100%;
            min-width: 0;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #fffdfa;
            color: var(--ink);
            font: inherit;
            padding: 11px 12px;
        }
        textarea { min-height: 96px; resize: vertical; }
        input:focus,
        select:focus,
        textarea:focus {
            border-color: var(--accent);
            outline: 2px solid rgba(232, 69, 10, 0.18);
        }
        .field-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--ink);
            border-radius: 6px;
            background: var(--ink);
            color: white;
            font: inherit;
            font-weight: 800;
            padding: 11px 14px;
            text-decoration: none;
            cursor: pointer;
            min-height: 44px;
        }
        .button.secondary {
            background: white;
            color: var(--ink);
        }
        .button.accent {
            background: var(--accent);
            border-color: var(--accent);
        }
        .button.danger {
            background: white;
            border-color: rgba(185, 28, 28, 0.28);
            color: var(--red);
        }
        .button.compact {
            min-height: 34px;
            padding: 7px 10px;
            font-size: 12px;
        }
        .button:disabled {
            opacity: 0.52;
            cursor: not-allowed;
        }
        .button.is-loading {
            pointer-events: none;
        }
        .form-actions {
            justify-content: flex-start;
        }
        .inline-field-action {
            align-items: flex-start;
        }
        .inline-field-action label {
            margin-bottom: 0;
        }
        .sync-form {
            margin-top: 14px;
        }
        .readonly-field {
            min-height: 44px;
            display: flex;
            align-items: center;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: rgba(10, 31, 68, 0.04);
            color: var(--body);
            padding: 11px 12px;
            font-weight: 700;
        }
        .state-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
        }
        .choice-card {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: white;
            cursor: pointer;
            text-transform: none;
            letter-spacing: 0;
        }
        .choice-card input { width: auto; }
        .choice-card strong {
            display: block;
            font-size: 20px;
            line-height: 1.1;
        }
        .choice-card small {
            display: block;
            color: var(--body);
            font-size: 13px;
            font-weight: 700;
            margin-top: 3px;
        }
        .choice-card:has(input:checked) {
            border-color: var(--accent);
            background: rgba(232, 69, 10, 0.07);
            box-shadow: inset 0 0 0 1px rgba(232, 69, 10, 0.15);
        }
        .event-context {
            display: grid;
            gap: 6px;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: rgba(10, 31, 68, 0.04);
        }
        .event-context strong {
            font-size: 18px;
            line-height: 1.2;
        }
        .event-context small {
            color: var(--body);
            font-size: 13px;
            font-weight: 700;
        }
        .photo-picker {
            display: grid;
            gap: 8px;
            padding: 16px;
            border: 1px dashed rgba(232, 69, 10, 0.45);
            border-radius: 8px;
            background: rgba(232, 69, 10, 0.06);
        }
        .photo-picker input[type=file] {
            border-color: rgba(232, 69, 10, 0.28);
            background: white;
            font-weight: 800;
        }
        .photo-picker span {
            color: var(--body);
            font-size: 13px;
        }
        .photo-status {
            border-radius: 6px;
            background: rgba(10, 31, 68, 0.06);
            color: var(--body);
            display: none;
            font-size: 13px;
            padding: 9px 10px;
        }
        .photo-status.is-visible { display: block; }
        .photo-status.is-error {
            background: rgba(185, 28, 28, 0.09);
            color: var(--red);
        }
        .capture-submit {
            min-height: 52px;
            font-size: 16px;
        }
        .alert {
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 16px;
            background: white;
            border: 1px solid var(--line);
            overflow-wrap: anywhere;
        }
        .alert.ok { border-color: rgba(21, 128, 61, 0.24); color: var(--green); }
        .alert.error { border-color: rgba(185, 28, 28, 0.24); color: var(--red); }
        .capture-image {
            display: block;
            width: 100%;
            max-height: 460px;
            object-fit: contain;
            background: #ece6dc;
            border: 1px solid var(--line);
            border-radius: 8px;
        }
        .empty {
            border: 1px dashed var(--line);
            border-radius: 8px;
            padding: 26px;
            background: rgba(255,255,255,0.62);
            color: var(--body);
            text-align: center;
        }
        .table-scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 720px; }
        th, td {
            padding: 11px 10px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
        }
        th {
            font-size: 12px;
            text-transform: uppercase;
            color: var(--muted);
            letter-spacing: 0.04em;
        }

        @media (max-width: 900px) {
            .review-layout {
                grid-template-columns: 1fr;
            }
            .capture-image {
                max-height: min(52vh, 420px);
            }
        }

        @media (max-width: 720px) {
            .topbar-inner,
            .hero-row {
                display: grid;
            }
            .nav { justify-content: flex-start; }
            .panel { padding: 14px; }
            .hero-actions,
            .hero-actions form,
            .form-actions,
            .sync-form {
                width: 100%;
            }
            .review-layout {
                gap: 12px;
            }
            .inline-field-action {
                display: grid;
                gap: 8px;
            }
            .field-grid {
                grid-template-columns: 1fr;
            }
            .state-grid { grid-template-columns: 1fr 1fr; }
            .button { width: 100%; }
        }

        @media (max-width: 480px) {
            .topbar {
                padding-left: 12px;
                padding-right: 12px;
            }
            main {
                padding: 18px 12px 42px;
            }
            .brand span:last-child {
                white-space: normal;
                line-height: 1.15;
            }
            .nav {
                width: 100%;
            }
            .nav a,
            .nav button {
                min-height: 38px;
                padding: 8px 9px;
                text-align: center;
            }
            .capture-image {
                max-height: 360px;
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="{{ auth()->check() ? route('events.index') : route('login') }}">
                <img class="brand-mark" src="{{ asset('assets/eric-event-roi-logo.png') }}" alt="Derivita event intelligence">
                <span>Event Lead Capture</span>
            </a>
            @auth
                <nav class="nav">
                    <a href="{{ route('events.index') }}">Events</a>
                    <a href="{{ route('captures.create') }}">Capture</a>
                    <a href="{{ route('captures.index') }}">Log</a>
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit">Log out</button>
                    </form>
                </nav>
            @endauth
        </div>
    </header>

    <main>
        @if (session('status'))
            <div class="alert ok">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert error">
                {{ $errors->first() }}
            </div>
        @endif

        {{ $slot }}
    </main>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/service-worker.js').catch(() => {});
            });
        }

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            if (event.defaultPrevented) {
                return;
            }

            if (form.dataset.submitting === 'true') {
                event.preventDefault();
                return;
            }

            form.dataset.submitting = 'true';

            const controls = Array.from(form.querySelectorAll('button, input[type="submit"]'));
            if (form.id) {
                controls.push(...document.querySelectorAll('[form="' + form.id + '"]'));
            }

            controls.forEach((control) => {
                if (control instanceof HTMLButtonElement || control instanceof HTMLInputElement) {
                    control.disabled = true;
                    control.classList.add('is-loading');
                    const busyLabel = control.dataset.busyLabel;
                    if (busyLabel && control instanceof HTMLButtonElement) {
                        control.textContent = busyLabel;
                    }
                }
            });
        });

        window.addEventListener('load', () => {
            const form = document.querySelector('form[data-auto-web-enrich="true"]');
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const storageKey = 'lead-capture:auto-web-enrich:' + form.action;
            if (window.sessionStorage.getItem(storageKey) === 'sent') {
                return;
            }

            window.sessionStorage.setItem(storageKey, 'sent');
            form.requestSubmit();
        });
    </script>
</body>
</html>

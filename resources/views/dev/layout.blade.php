{{-- TEMPORARY development page — remove resources/views/dev/ before committing --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · Dev</title>
    <style>
        :root { color-scheme: light dark; --bg:#fafafa; --card:#fff; --text:#18181b; --muted:#71717a; --line:#e4e4e7; --accent:#4f46e5; }
        @media (prefers-color-scheme: dark) { :root { --bg:#09090b; --card:#18181b; --text:#f4f4f5; --muted:#a1a1aa; --line:#27272a; --accent:#818cf8; } }
        * { box-sizing: border-box; }
        body { margin:0; font:14px/1.5 system-ui, sans-serif; background:var(--bg); color:var(--text); }
        main { max-width:1200px; margin:0 auto; padding:24px 16px; }
        nav { display:flex; gap:16px; margin-bottom:16px; font-weight:600; }
        nav a { color:var(--accent); text-decoration:none; }
        .warn { padding:10px 14px; border-radius:8px; background:#fef3c7; color:#92400e; margin-bottom:16px; }
        .wrap { overflow-x:auto; background:var(--card); border:1px solid var(--line); border-radius:12px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:8px 12px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; white-space:nowrap; }
        th { font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
        code { font:600 15px ui-monospace, monospace; letter-spacing:.08em; }
        .muted { color:var(--muted); font-size:12px; }
        .badge { display:inline-block; padding:1px 8px; border-radius:999px; font-size:12px; font-weight:600; background:var(--line); }
        .active { background:#d1fae5; color:#065f46; } .used { background:#e0e7ff; color:#3730a3; }
        .expired, .locked { background:#fee2e2; color:#991b1b; }
        a.open { color:var(--accent); font-weight:600; }
        .url { max-width:420px; overflow:hidden; text-overflow:ellipsis; }
    </style>
</head>
<body>
<main>
    <nav>
        <a href="{{ route('dev.otps') }}">OTP codes</a>
        <a href="{{ route('dev.email-links') }}">Email links</a>
    </nav>
    <div class="warn">Development only (APP_ENV=local). Shows secrets in plain text — remove before committing.</div>
    <h1>@yield('title')</h1>
    @yield('content')
</main>
</body>
</html>

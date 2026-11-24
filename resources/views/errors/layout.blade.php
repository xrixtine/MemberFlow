<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('code') · @yield('title') · MemberFlow</title>
    {{-- Self-contained styles: error pages must render even if assets or the database are unavailable. --}}
    <style>
        :root { --dark:#202623; --lime:#c8f27c; --bg:#f5f3ef; --text:#242b25; --muted:#5f6862; --line:#e8e8e2; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, Arial, sans-serif; background: var(--bg); color: var(--text);
               min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 40px 32px; max-width: 520px; width: 100%; }
        .logo { font-size: 24px; font-weight: 800; letter-spacing: -1px; margin-bottom: 32px; }
        .logo span { color: #5d7f24; }
        .code { display: inline-block; background: var(--dark); color: var(--lime); font-weight: 800; font-size: 13px;
                letter-spacing: 2px; padding: 6px 12px; border-radius: 30px; margin-bottom: 16px; }
        h1 { font-size: 30px; letter-spacing: -1px; margin-bottom: 10px; }
        p { color: var(--muted); font-size: 15px; line-height: 1.6; }
        .actions { margin-top: 28px; display: flex; gap: 10px; flex-wrap: wrap; }
        a.btn { display: inline-block; text-decoration: none; font-weight: 700; font-size: 14px; padding: 12px 18px; border-radius: 12px; }
        a.primary { background: var(--lime); color: var(--dark); }
        a.secondary { border: 1px solid var(--line); color: var(--text); }
        a:focus-visible { outline: 3px solid #5d7f24; outline-offset: 2px; }
    </style>
</head>
<body>
<main class="card">
    <div class="logo">memberflow<span>.</span></div>
    <div class="code">ERROR @yield('code')</div>
    <h1>@yield('title')</h1>
    <p>@yield('message')</p>
    <div class="actions">
        <a class="btn primary" href="{{ url('/') }}">Go to my dashboard</a>
        <a class="btn secondary" href="javascript:history.back()">Go back</a>
    </div>
</main>
</body>
</html>

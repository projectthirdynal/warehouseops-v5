<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? $appName }}</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f8fb;
            --surface: #ffffff;
            --text: #122033;
            --muted: #5e6c84;
            --border: #d8e0ec;
            --primary: #2563eb;
            --primary-soft: #eff6ff;
            --success-soft: #ecfdf3;
            --success-text: #166534;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Inter, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.55;
        }
        .wrap {
            max-width: 920px;
            margin: 0 auto;
            padding: 40px 20px 80px;
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 28px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.04);
        }
        h1, h2, h3 { margin: 0 0 12px; line-height: 1.2; }
        h1 { font-size: 30px; }
        h2 { font-size: 20px; margin-top: 28px; }
        p, li { color: var(--muted); }
        a { color: var(--primary); text-decoration: none; }
        a:hover { text-decoration: underline; }
        code {
            background: #0f172a;
            color: #f8fafc;
            padding: 2px 6px;
            border-radius: 6px;
            font-size: 13px;
        }
        .grid {
            display: grid;
            gap: 16px;
        }
        .notice {
            background: var(--primary-soft);
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 14px 16px;
        }
        .success {
            background: var(--success-soft);
            border: 1px solid #bbf7d0;
            color: var(--success-text);
            border-radius: 10px;
            padding: 14px 16px;
        }
        .meta {
            display: grid;
            gap: 6px;
            margin-top: 16px;
            font-size: 14px;
        }
        .meta strong { color: var(--text); }
        ul { padding-left: 20px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            @yield('content')
        </div>
    </div>
</body>
</html>

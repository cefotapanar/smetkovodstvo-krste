<!doctype html>
<html lang="mk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>@yield('title', 'Сметководство КРСТЕ')</title>
<style>
  :root {
    --bg: #f4f5f7; --panel: #ffffff; --ink: #1c2430; --muted: #5f6b7a; --line: #dde1e7;
    --accent: #1f5f8b; --accent-soft: #e7f0f7; --ok: #1e7a46; --ok-soft: #e8f5ed;
    --warn: #9a5b00; --warn-soft: #fdf3e2; --new: #b4232c;
    --owner: #1f5f8b; --accountant: #6b3fa0;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; }
  body { background: var(--bg); color: var(--ink); font: 15px/1.55 "Segoe UI", system-ui, -apple-system, sans-serif; }
  a { color: var(--accent); }
  button, input, select, textarea { font: inherit; color: inherit; }
  .btn { display: inline-block; border: 1px solid var(--accent); background: var(--accent); color: #fff; border-radius: 6px; padding: 6px 14px; cursor: pointer; text-decoration: none; }
  .btn:hover { filter: brightness(1.1); }
  .btn.ghost { background: transparent; color: var(--accent); }
  .btn.ok { background: var(--ok); border-color: var(--ok); }
  .btn.small { padding: 3px 10px; font-size: 13px; }
  input[type=text], input[type=email], input[type=password], select, textarea {
    width: 100%; border: 1px solid var(--line); border-radius: 6px; padding: 7px 9px; background: #fff;
  }
  textarea { resize: vertical; min-height: 64px; }
  input:focus, select:focus, textarea:focus { outline: 2px solid var(--accent-soft); border-color: var(--accent); }
  .flash { background: var(--ok-soft); border: 1px solid #b9dfc6; color: var(--ok); padding: 8px 12px; border-radius: 6px; margin: 0 0 12px; }
  .errors { background: #fdeeee; border: 1px solid #f0c2c2; color: #8c1d1d; padding: 8px 12px; border-radius: 6px; margin: 0 0 12px; }
</style>
@yield('head')
</head>
<body>
@yield('body')
</body>
</html>

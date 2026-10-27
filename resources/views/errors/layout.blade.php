<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Something went wrong') | FurniCraft</title>
    <style>
        :root { color-scheme: light; font-family: "Segoe UI", Arial, sans-serif; color: #281d17; background: #f8f6f2; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 1.5rem; }
        main { width: min(100%, 34rem); padding: 2rem; background: #fff; border: 1px solid #e2d4c8; border-radius: 12px; }
        .tag { color: #76503a; font-size: .8rem; font-weight: 700; letter-spacing: .08em; }
        h1 { margin: .5rem 0; font-size: clamp(1.6rem, 6vw, 2.2rem); }
        p { line-height: 1.6; color: #514137; }
        a { display: inline-flex; min-height: 44px; align-items: center; margin-top: .5rem; padding: 0 1rem; color: #fff; background: #4a2e21; border-radius: 8px; text-decoration: none; }
        a:focus-visible { outline: 3px solid #c19c64; outline-offset: 3px; }
    </style>
</head>
<body>
<main>
    <span class="tag">FURNICRAFT · LANCE FURNITURE SHOP</span>
    <h1>@yield('heading', 'Something went wrong')</h1>
    <p>@yield('message', 'Please try again in a moment, or return to the catalog.')</p>
    <a href="{{ url('/') }}">Return to FurniCraft</a>
</main>
</body>
</html>

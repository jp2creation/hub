<!doctype html>
<html lang="fr" style="{{ \App\Support\CrmTheme::styleAttribute() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Maintenance - JP2 Hub</title>
    <style>
        :root {
            color-scheme: light;
            --accent: var(--theme-primary-color);
            --text: #172033;
            --muted: #667085;
            --surface: #ffffff;
            --border: #e5e7eb;
            --bg: #f5f7fb;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 32px 18px;
            background: var(--bg);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        main {
            width: min(100%, 520px);
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            padding: 34px;
            box-shadow: 0 18px 50px rgb(15 23 42 / 10%);
        }

        .mark {
            width: 52px;
            height: 52px;
            border-radius: 8px;
            display: grid;
            place-items: center;
            background: color-mix(in srgb, var(--accent) 10%, white);
            color: var(--accent);
            font-weight: 800;
            letter-spacing: 0;
        }

        h1 {
            margin: 22px 0 10px;
            font-size: 28px;
            line-height: 1.15;
        }

        p {
            margin: 0;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.6;
        }

        .footer {
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
            font-size: 14px;
        }
    </style>
</head>
<body>
    <main>
        <div class="mark">MS</div>
        <h1>HUB en maintenance</h1>
        <p>Une mise &agrave; jour est en cours. Le service revient dans quelques minutes.</p>
        <p class="footer">Merci de patienter avant de relancer votre action.</p>
    </main>
</body>
</html>

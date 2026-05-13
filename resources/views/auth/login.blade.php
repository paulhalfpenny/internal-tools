<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In — Filter Internal Tools</title>
    @vite(['resources/css/app.css'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --navy:    #002f5f;
            --blue:    #2B6FE8;
            --blue-lt: rgba(43, 111, 232, 0.12);
            --ink:     #002f5f;
            --muted:   #7E93A8;
            --rule:    #E4EBF2;
        }

        html, body { height: 100%; }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100%;
            font-family: 'DM Sans', sans-serif;
            background: #F7F9FB;
        }

        /* Subtle dot-grid over the whole page */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: radial-gradient(circle, rgba(0,47,95,0.06) 1px, transparent 1px);
            background-size: 28px 28px;
            pointer-events: none;
            z-index: 0;
        }

        .card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 400px;
            padding: 3rem 3.5rem;
            background: #fff;
            border: 1px solid var(--rule);
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,47,95,0.08), 0 1px 4px rgba(0,47,95,0.04);
        }

        .card-logo {
            margin-bottom: 2.5rem;
        }
        .card-logo img {
            height: 40px;
        }

        .card-label {
            font-size: 0.65rem;
            font-weight: 600;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--blue);
            margin-bottom: 0.6rem;
        }

        .card-heading {
            font-size: 2rem;
            font-weight: 600;
            line-height: 1.1;
            letter-spacing: -0.03em;
            color: var(--ink);
            margin-bottom: 0.5rem;
        }

        .card-sub {
            font-size: 0.84rem;
            font-weight: 300;
            color: var(--muted);
            line-height: 1.6;
            margin-bottom: 2.5rem;
        }

        .card-divider {
            height: 1px;
            background: var(--rule);
            margin-bottom: 2.5rem;
        }

        .google-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 13px 18px;
            background: #fff;
            border: 1.5px solid var(--rule);
            border-radius: 8px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--ink);
            cursor: pointer;
            text-decoration: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
            box-shadow: 0 1px 3px rgba(0,47,95,0.06);
        }
        .google-btn:hover {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px var(--blue-lt), 0 2px 10px rgba(0,47,95,0.1);
            transform: translateY(-1px);
        }
        .google-btn svg { flex-shrink: 0; }

        /* Entrance animation */
        .card {
            animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        .card-logo    { animation: fadeUp 0.5s ease 0.15s both; }
        .card-label   { animation: fadeUp 0.5s ease 0.22s both; }
        .card-heading { animation: fadeUp 0.5s ease 0.27s both; }
        .card-sub     { animation: fadeUp 0.5s ease 0.32s both; }
        .card-divider { animation: fadeUp 0.5s ease 0.36s both; }
        .google-btn   { animation: fadeUp 0.5s ease 0.40s both; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

    <div class="card">
        <div class="card-logo">
            <img src="/assets/filter-logo-blue-rgb.png" alt="Filter">
        </div>

        <div class="card-label">Sign in</div>
        <h1 class="card-heading">Welcome back.</h1>
        <p class="card-sub">Use your Filter Google account to login.</p>

        <div class="card-divider"></div>

        <a href="{{ route('auth.google') }}" class="google-btn">
            <svg width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
            </svg>
            Sign in with Google
        </a>

    </div>

<script>
  window.FeedbackFlowConfig = {
    endpoint: 'https://bugfeed.co.uk/api/feedback',
    projectId: 'Xgd8K5hykzc3Q-MI',
    position: 'bottom-right',
    primaryColor: '#e1236c',
    theme: 'dark'
  };
</script>
<script src="https://bugfeed.co.uk/widget.js" defer></script>
</body>
</html>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('nova.sign_in.title') }} — {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #f1f5f9;
            color: #0f172a;
            font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        .card {
            width: min(26rem, calc(100vw - 2rem));
            padding: 2rem;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgb(15 23 42 / 12%), 0 8px 24px rgb(15 23 42 / 8%);
        }
        h1 { margin: 0 0 .5rem; font-size: 1.25rem; }
        p { margin: 0 0 1.25rem; color: #475569; }
        label { display: block; margin-bottom: .375rem; font-weight: 600; }
        input {
            width: 100%;
            padding: .625rem .75rem;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font: inherit;
            box-sizing: border-box;
        }
        button {
            margin-top: 1rem;
            width: 100%;
            padding: .625rem 1rem;
            border: 0;
            border-radius: 8px;
            background: #1e293b;
            color: #fff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }
        .status { padding: .75rem; border-radius: 8px; background: #ecfdf5; color: #065f46; margin-bottom: 1rem; }
        .error { padding: .75rem; border-radius: 8px; background: #fef2f2; color: #991b1b; margin-bottom: 1rem; }
        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; color: #e2e8f0; }
            .card { background: #1e293b; box-shadow: none; }
            p { color: #94a3b8; }
            input { background: #0f172a; border-color: #334155; color: inherit; }
            button { background: #38bdf8; color: #0f172a; }
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>{{ __('nova.sign_in.title') }}</h1>
        <p>{{ __('nova.sign_in.intro') }}</p>

        @if (session('status'))
            <div class="status">{{ session('status') }}</div>
        @endif

        @error('email')
            <div class="error">{{ $message }}</div>
        @enderror

        <form method="POST" action="{{ route('nova.sign-in.send') }}">
            @csrf
            <label for="email">{{ __('nova.sign_in.email') }}</label>
            <input id="email" name="email" type="email" required autofocus autocomplete="email"
                   value="{{ old('email') }}">
            <button type="submit">{{ __('nova.sign_in.submit') }}</button>
        </form>
    </main>
</body>
</html>

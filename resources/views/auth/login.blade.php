<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Log in - {{ config('app.name') }}</title>

    @vite(['resources/css/app.css'])
</head>
<body class="font-sans antialiased bg-background text-foreground">
<main class="min-h-screen flex items-center justify-center p-4">
    <form method="POST" action="{{ route('login.store') }}" class="w-full max-w-md space-y-4 rounded-lg border bg-card p-6 text-card-foreground shadow-sm">
        @csrf

        <h1 class="text-2xl font-semibold tracking-tight text-center">Log in to {{ config('app.name') }}</h1>

        <div class="space-y-1.5">
            <label for="email" class="text-sm font-medium">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">

            @error('email')
                <p class="text-sm text-destructive">{{ $message }}</p>
            @enderror
        </div>

        <div class="space-y-1.5">
            <label for="password" class="text-sm font-medium">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">

            @error('password')
                <p class="text-sm text-destructive">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="h-10 w-full rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90">
            Log in
        </button>
    </form>
</main>
</body>
</html>

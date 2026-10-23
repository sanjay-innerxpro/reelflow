<x-guest :title="'Sign in to '.config('app.name')">
    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf
        <div>
            <label class="label" for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="input">
        </div>
        <div>
            <div class="flex items-baseline justify-between">
                <label class="label" for="password">Password</label>
                <a href="{{ route('password.request') }}" class="text-xs text-indigo-600 hover:underline">Forgot password?</a>
            </div>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="input">
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
        <button class="btn btn-primary w-full">Sign in</button>
        <p class="text-center text-xs text-slate-500">Access is by invitation only.</p>
    </form>
</x-guest>

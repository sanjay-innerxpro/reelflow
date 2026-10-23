<x-guest title="Reset your password">
    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf
        <p class="text-sm text-slate-600">Enter your account email and we will send you a link to choose a new password.</p>
        <div>
            <label class="label" for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus class="input">
        </div>
        <button class="btn btn-primary w-full">Send reset link</button>
        <p class="text-center text-sm"><a href="{{ route('login') }}" class="text-indigo-600 hover:underline">Back to sign in</a></p>
    </form>
</x-guest>

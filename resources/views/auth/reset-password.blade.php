<x-guest :title="$invite ? 'Welcome! Set your password' : 'Choose a new password'">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="invite" value="{{ $invite ? 1 : 0 }}">
        <div>
            <label class="label" for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required class="input" autocomplete="username">
        </div>
        <div>
            <label class="label" for="password">New password</label>
            <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password" class="input">
            <p class="help">At least 12 characters.</p>
        </div>
        <div>
            <label class="label" for="password_confirmation">Repeat password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password" class="input">
        </div>
        <button class="btn btn-primary w-full">{{ $invite ? 'Create my account' : 'Save password' }}</button>
    </form>
</x-guest>

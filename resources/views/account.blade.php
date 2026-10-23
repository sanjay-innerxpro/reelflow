<x-layout title="Account">
    <x-slot:header><h1 class="text-xl font-semibold">Account</h1></x-slot:header>

    <form method="POST" action="{{ route('account.password') }}" class="card card-body max-w-md space-y-4">
        @csrf @method('PUT')
        <h2 class="card-title">Change password</h2>
        <p class="text-sm text-slate-500">Signed in as {{ auth()->user()->email }}</p>
        <div>
            <label class="label" for="current_password">Current password</label>
            <input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="input">
        </div>
        <div>
            <label class="label" for="password">New password (12+ characters)</label>
            <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password" class="input">
        </div>
        <div>
            <label class="label" for="password_confirmation">Repeat new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password" class="input">
        </div>
        <button class="btn btn-primary">Change password</button>
    </form>
</x-layout>

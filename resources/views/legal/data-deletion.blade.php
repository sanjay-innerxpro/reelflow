<x-legal-page title="Data Deletion Instructions">
    <p>To delete the data {{ config('app.name') }} holds about a connected account:</p>
    <ol class="list-decimal pl-5">
        <li>Sign in to the dashboard, open <strong>Connected accounts</strong> and choose <strong>Disconnect</strong>. Stored access and refresh tokens are deleted immediately and automatic posting stops.</li>
        <li>Remove the app's access on the platform: Facebook → Settings &amp; privacy → Settings → Business Integrations → remove the app; Google → <a class="link" href="https://myaccount.google.com/permissions">myaccount.google.com/permissions</a>.</li>
        <li>To also delete publishing history and uploaded videos, email {{ config('reelflow.admin_email') ?: 'the operator' }}; it is completed within 30 days.</li>
    </ol>
</x-legal-page>

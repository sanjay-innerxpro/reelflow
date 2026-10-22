<x-legal-page title="Privacy Policy">
    <p>{{ config('app.name') }} is a private tool used by its operator to publish their own short videos to Facebook Pages, Instagram professional accounts and YouTube channels that they manage. It has no public sign-up.</p>
    <h2 class="text-lg font-semibold">Data we access</h2>
    <ul class="list-disc pl-5">
        <li><strong>Meta (Facebook/Instagram):</strong> the names and IDs of Pages and linked Instagram professional accounts the operator chooses to connect, Page access tokens, and — where permitted — basic post metrics (views, likes, comments, shares). Permissions used: pages_show_list, pages_read_engagement, pages_manage_posts, business_management, instagram_basic, instagram_content_publish, instagram_manage_insights, read_insights.</li>
        <li><strong>Google (YouTube):</strong> the operator's channel ID and title, OAuth tokens, and public statistics of videos uploaded by this tool. Scopes used: youtube.upload, youtube.readonly.</li>
        <li><strong>Videos and captions</strong> uploaded by the operator from their own computer.</li>
    </ul>
    <h2 class="text-lg font-semibold">How it is used</h2>
    <p>Solely to upload and publish the operator's videos at the times they configure, to show publishing status and metrics in the private dashboard, and to email the operator about publishing results. Data is not sold, shared with third parties for advertising, or used to build profiles of other people. {{ config('app.name') }}'s use of information received from Google APIs adheres to the Google API Services User Data Policy, including the Limited Use requirements.</p>
    <h2 class="text-lg font-semibold">Storage and security</h2>
    <p>Data is stored on the operator's server. Access tokens are encrypted at rest and never shown in the browser or emails. Video files are kept in private storage reachable only through short-lived signed links.</p>
    <h2 class="text-lg font-semibold">AI processing</h2>
    <p>If enabled by the operator, a few still frames (and optionally the audio transcript) of a video are sent to the configured AI service solely to draft captions.</p>
    <h2 class="text-lg font-semibold">Retention and deletion</h2>
    <p>Disconnecting an account in the dashboard deletes its stored tokens immediately. See <a class="link" href="{{ route('legal.data-deletion') }}">data deletion instructions</a>. You can also revoke access at any time in Facebook Settings → Business Integrations or at <a class="link" href="https://myaccount.google.com/permissions">myaccount.google.com/permissions</a>.</p>
</x-legal-page>

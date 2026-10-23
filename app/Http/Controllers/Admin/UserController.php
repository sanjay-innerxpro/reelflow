<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PostStatus;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Controller;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use App\Notifications\InviteNotification;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Super-admin: invite users (each gets their own workspace), set limits,
 * suspend and resume.
 */
class UserController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(): View
    {
        $byWorkspace = fn ($query) => $query->groupBy('workspace_id')->pluck('n', 'workspace_id');

        return view('admin.users.index', [
            'users' => User::with('workspace')->orderBy('name')->get(),
            'storage' => $byWorkspace(Video::allWorkspaces()->selectRaw('workspace_id, sum(size_bytes) as n')->whereNotNull('storage_key')->whereNull('storage_deleted_at')),
            'videos' => $byWorkspace(Video::allWorkspaces()->selectRaw('workspace_id, count(*) as n')),
            'accounts' => $byWorkspace(SocialAccount::allWorkspaces()->selectRaw('workspace_id, count(*) as n')),
            'published' => $byWorkspace(ScheduledPost::allWorkspaces()->selectRaw('workspace_id, count(*) as n')
                ->where('status', PostStatus::Published->value)->where('published_at', '>=', now()->subDays(7))),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', ['user' => null, 'workspace' => new Workspace([
            'storage_limit_mb' => config('reelflow.workspaces.storage_limit_mb'),
            'max_accounts' => config('reelflow.workspaces.max_accounts'),
            'daily_ai_limit' => config('reelflow.workspaces.daily_ai_limit'),
        ])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
        ] + $this->limitRules());

        $user = DB::transaction(function () use ($data) {
            $workspace = Workspace::create(['name' => ($data['workspace_name'] ?? null) ?: $data['name']."'s workspace"] + $this->limits($data));
            $user = new User(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => Hash::make(Str::random(48))]);
            $user->workspace_id = $workspace->id;
            $user->save();
            $workspace->update(['owner_id' => $user->id]);

            return $user;
        });
        $this->activity->log('admin.user_invited', $user, "Invited {$user->email}");

        return $this->sendInvite($request, $user, 'User created.');
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', ['user' => $user, 'workspace' => $user->workspace]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']] + $this->limitRules());

        $user->update(['name' => $data['name']]);
        $user->workspace->update(['name' => ($data['workspace_name'] ?? null) ?: $user->workspace->name] + $this->limits($data));
        $this->activity->log('admin.user_updated', $user, "Limits updated for {$user->email}");

        return redirect()->route('admin.users.index')->with('status', "{$user->name} saved.");
    }

    public function invite(Request $request, User $user): RedirectResponse
    {
        return $this->sendInvite($request, $user, 'New invitation link created.');
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        abort_if($user->workspace_id === $request->user()->workspace_id, 422, 'You cannot suspend your own workspace.');

        $suspend = ! $user->workspace->isSuspended();
        $user->workspace->update(['suspended_at' => $suspend ? now() : null]);
        $this->activity->log($suspend ? 'admin.user_suspended' : 'admin.user_resumed', $user, ($suspend ? 'Suspended ' : 'Resumed ').$user->email);

        return back()->with('status', $suspend
            ? "{$user->name} suspended: they cannot sign in and nothing is published for them."
            : "{$user->name} resumed.");
    }

    private function sendInvite(Request $request, User $user, string $prefix): RedirectResponse
    {
        $url = PasswordResetController::inviteUrl($user);

        try {
            $user->notifyNow(new InviteNotification($url, $request->user()->name));
            $mailed = config('mail.default') !== 'log';
        } catch (Throwable) {
            $mailed = false;
        }

        return redirect()->route('admin.users.index')
            ->with('status', $prefix.($mailed ? " Invitation emailed to {$user->email}." : ' Email is not set up, so send this link yourself.'))
            ->with('invite_url', $url)
            ->with('invite_email', $user->email);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function limitRules(): array
    {
        return [
            'workspace_name' => ['nullable', 'string', 'max:120'],
            'storage_limit_mb' => ['required', 'integer', 'min:0', 'max:1048576'],
            'max_accounts' => ['required', 'integer', 'min:0', 'max:1000'],
            'daily_ai_limit' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    private function limits(array $data): array
    {
        return [
            'storage_limit_mb' => (int) $data['storage_limit_mb'],
            'max_accounts' => (int) $data['max_accounts'],
            'daily_ai_limit' => (int) $data['daily_ai_limit'],
        ];
    }
}

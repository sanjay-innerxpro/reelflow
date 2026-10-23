<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'reels:create-user {email} {--name=Admin} {--admin : Super-admin (manages users)}';

    protected $description = 'Create a user with their own workspace, or reset an existing user\'s password';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = (string) $this->secret('Password (min 12 characters)');

        $validator = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:12'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if ($user) {
            $user->update(['password' => Hash::make($password)]);
            $this->info("Password of {$email} reset.");
        } else {
            DB::transaction(function () use ($email, $password) {
                $workspace = Workspace::create([
                    'name' => $this->option('name')."'s workspace",
                    'storage_limit_mb' => $this->option('admin') ? 0 : config('reelflow.workspaces.storage_limit_mb'),
                    'max_accounts' => $this->option('admin') ? 0 : config('reelflow.workspaces.max_accounts'),
                    'daily_ai_limit' => $this->option('admin') ? 0 : config('reelflow.workspaces.daily_ai_limit'),
                ]);
                $user = new User(['name' => $this->option('name'), 'email' => $email, 'password' => Hash::make($password)]);
                $user->forceFill(['workspace_id' => $workspace->id, 'is_admin' => (bool) $this->option('admin')])->save();
                $workspace->update(['owner_id' => $user->id]);
            });
            $this->info("User {$email} created with their own workspace".($this->option('admin') ? ' (admin).' : '.'));
        }

        return self::SUCCESS;
    }
}

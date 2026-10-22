<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'reels:create-user {email} {--name=Admin}';

    protected $description = 'Create (or reset the password of) a dashboard user';

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

        User::updateOrCreate(['email' => $email], ['name' => $this->option('name'), 'password' => Hash::make($password)]);
        $this->info("User {$email} saved.");

        return self::SUCCESS;
    }
}

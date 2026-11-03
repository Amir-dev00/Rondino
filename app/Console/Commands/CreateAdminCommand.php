<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminCommand extends Command
{
    protected $signature = 'rondino:create-admin {email} {--name=مدیر} {--password=}';

    protected $description = 'Create or update an admin team account (no public registration)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->option('password') ?: $this->secret('Password');

        $v = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => 'required|email', 'password' => 'required|min:10']
        );
        if ($v->fails()) {
            $this->error($v->errors()->first());

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $this->option('name'),
                'password' => Hash::make($password),
                'is_admin' => true,
                'is_active' => true,
            ]
        );

        $this->info('Admin ready: '.$user->email);

        return self::SUCCESS;
    }
}

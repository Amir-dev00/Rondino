<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminCommand extends Command
{
    protected $signature = 'rondino:create-admin
        {email : Admin email address}
        {--name=مدیر : Display name}
        {--password= : Password (avoid on shared hosts — prefer --password-file)}
        {--password-file= : Absolute path to a private file whose first line is the password}';

    protected $description = 'Create or update an admin team account (no public registration)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->resolvePassword();

        if ($password === null || $password === '') {
            $this->error('Password required via --password-file (preferred) or --password.');

            return self::FAILURE;
        }

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

    private function resolvePassword(): ?string
    {
        $file = $this->option('password-file');
        if (is_string($file) && $file !== '') {
            if (! is_file($file) || ! is_readable($file)) {
                $this->error('Password file not readable: '.$file);

                return null;
            }

            $line = preg_split("/\r\n|\n|\r/", (string) file_get_contents($file))[0] ?? '';

            return trim($line);
        }

        $direct = $this->option('password');
        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        if ($this->input->isInteractive()) {
            return $this->secret('Password');
        }

        return null;
    }
}

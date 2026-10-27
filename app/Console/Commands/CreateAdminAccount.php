<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class CreateAdminAccount extends Command
{
    protected $signature = 'furnicraft:admin:create';

    protected $description = 'Create an administrator account using interactive, hidden password entry';

    public function handle(): int
    {
        $data = [
            'name' => trim((string) $this->ask('Admin name')),
            'email' => Str::lower(trim((string) $this->ask('Admin email'))),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = (string) $this->secret('Admin password (at least 12 characters)');
        $passwordValidator = Validator::make(['password' => $password], [
            'password' => ['required', 'string', Password::defaults()],
        ]);

        if ($passwordValidator->fails()) {
            foreach ($passwordValidator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $confirmation = (string) $this->secret('Confirm admin password');
        if (! hash_equals($password, $confirmation)) {
            $this->error('The password confirmation does not match. No administrator was created.');

            return self::FAILURE;
        }

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($password),
            'role' => 'admin',
            'branch' => config('furnicraft.branch'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->info('Administrator account created. The password was not displayed.');

        return self::SUCCESS;
    }
}

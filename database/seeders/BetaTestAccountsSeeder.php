<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class BetaTestAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (! filter_var(env('BETA_SEED_ACCOUNTS', false), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Set BETA_SEED_ACCOUNTS=true to explicitly create configured beta accounts.');
        }

        $accounts = [
            'admin' => ['name' => env('BETA_ADMIN_NAME'), 'email' => env('BETA_ADMIN_EMAIL'), 'password' => env('BETA_ADMIN_PASSWORD')],
            'customer' => ['name' => env('BETA_CUSTOMER_NAME'), 'email' => env('BETA_CUSTOMER_EMAIL'), 'password' => env('BETA_CUSTOMER_PASSWORD')],
        ];

        foreach ($accounts as $role => $account) {
            $validator = Validator::make($account, [
                'name' => ['required', 'string', 'max:100'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', Password::defaults()],
            ]);

            if ($validator->fails()) {
                throw new RuntimeException("Configured beta {$role} account is incomplete or does not meet password policy: ".implode(' ', $validator->errors()->all()));
            }

            User::firstOrCreate(
                ['email' => mb_strtolower(trim($account['email']))],
                [
                    'name' => trim($account['name']),
                    'password' => Hash::make($account['password']),
                    'role' => $role,
                    'branch' => config('furnicraft.branch'),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}

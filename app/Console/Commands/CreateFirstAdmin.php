<?php

namespace App\Console\Commands;

use App\Models\Pharmacist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateFirstAdmin extends Command
{
    protected $signature = 'pharmacists:create-first-admin
        {--first-name= : Administrator first name}
        {--last-name= : Administrator last name}
        {--username= : Administrator username}
        {--phone= : Administrator phone number}
        {--salary= : Administrator salary}';

    protected $description = 'Create the first administrator from the local command line';

    public function handle(): int
    {
        if (Pharmacist::where('is_admin', true)->exists()) {
            $this->error('An administrator already exists. No account was created.');

            return self::FAILURE;
        }

        $data = [
            'first_name' => $this->option('first-name') ?: $this->ask('First name'),
            'last_name' => $this->option('last-name') ?: $this->ask('Last name'),
            'username' => $this->option('username') ?: $this->ask('Username'),
            'phone' => $this->option('phone') ?: $this->ask('Phone'),
            'salary' => $this->option('salary') ?? $this->ask('Salary'),
            'password' => $this->secret('Password'),
        ];

        $confirmation = $this->secret('Confirm password');

        if ($data['password'] !== $confirmation) {
            $this->error('Passwords do not match. No account was created.');

            return self::FAILURE;
        }

        $validator = Validator::make($data, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', Rule::unique('pharmacists', 'username')],
            'phone' => ['required', 'string', 'max:255', Rule::unique('pharmacists', 'phone')],
            'salary' => ['required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $created = DB::transaction(function () use ($data): bool {
            if (Pharmacist::where('is_admin', true)->lockForUpdate()->exists()) {
                return false;
            }

            Pharmacist::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'username' => $data['username'],
                'password' => Hash::make($data['password']),
                'phone' => $data['phone'],
                'employment_date' => now(),
                'salary' => $data['salary'],
                'is_admin' => true,
            ]);

            return true;
        });

        if (! $created) {
            $this->error('An administrator already exists. No account was created.');

            return self::FAILURE;
        }

        $this->info('The first administrator account was created.');

        return self::SUCCESS;
    }
}

<?php

namespace Tests\Feature;

use App\Models\Pharmacist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PharmacistAccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function accountData(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Sam',
            'last_name' => 'Nasser',
            'username' => 'sam_nasser',
            'password' => 'a-long-password',
            'phone' => '5551234',
            'salary' => 4200.50,
        ], $overrides);
    }

    private function pharmacist(bool $isAdmin, string $username): Pharmacist
    {
        return Pharmacist::create([
            'first_name' => 'Existing',
            'last_name' => 'Pharmacist',
            'username' => $username,
            'password' => Hash::make('existing-password'),
            'phone' => $username.'-phone',
            'employment_date' => now(),
            'salary' => 3000,
            'is_admin' => $isAdmin,
        ]);
    }

    private function tokenFor(Pharmacist $pharmacist): string
    {
        $response = $this->postJson('/api/Login', [
            'username' => $pharmacist->username,
            'password' => 'existing-password',
        ])->assertOk();

        return $response->json('token');
    }

    public function test_guest_cannot_create_an_account_even_when_requesting_admin_access(): void
    {
        $this->postJson('/api/RegisterPharmasict', $this->accountData(['is_admin' => true]))
            ->assertUnauthorized();

        $this->assertDatabaseCount('pharmacists', 0);
    }

    public function test_regular_pharmacist_cannot_create_an_account(): void
    {
        $regular = $this->pharmacist(false, 'regular');
        $token = $this->tokenFor($regular);

        $this->withToken($token)
            ->postJson('/api/RegisterPharmasict', $this->accountData(['is_admin' => true]))
            ->assertForbidden();

        $this->assertDatabaseCount('pharmacists', 1);
    }

    public function test_administrator_creates_regular_pharmacist_and_sets_salary(): void
    {
        $admin = $this->pharmacist(true, 'admin');
        $token = $this->tokenFor($admin);

        $this->withToken($token)
            ->postJson('/api/RegisterPharmasict', $this->accountData())
            ->assertCreated();

        $created = Pharmacist::where('username', 'sam_nasser')->firstOrFail();
        $this->assertFalse((bool) $created->is_admin);
        $this->assertEquals(4200.50, $created->salary);
        $this->assertTrue(Hash::check('a-long-password', $created->password));
        $this->assertDatabaseCount('pharmacists', 2);
    }

    public function test_client_supplied_admin_flag_is_ignored(): void
    {
        $admin = $this->pharmacist(true, 'admin');
        $token = $this->tokenFor($admin);

        $this->withToken($token)
            ->postJson('/api/RegisterPharmasict', $this->accountData(['is_admin' => true]))
            ->assertCreated();

        $this->assertDatabaseHas('pharmacists', [
            'username' => 'sam_nasser',
            'is_admin' => false,
        ]);
    }

    public function test_account_update_cannot_be_used_by_guest_or_regular_pharmacist_to_take_over_admin(): void
    {
        $admin = $this->pharmacist(true, 'admin');
        $regular = $this->pharmacist(false, 'regular');
        $payload = ['password' => 'attacker-password', 'is_admin' => true];

        $this->putJson('/api/UpdatePharmacist/'.$admin->id, $payload)->assertUnauthorized();
        $this->withToken($this->tokenFor($regular))
            ->putJson('/api/UpdatePharmacist/'.$admin->id, $payload)
            ->assertForbidden();

        $this->assertTrue(Hash::check('existing-password', $admin->fresh()->password));
        $this->assertFalse((bool) $regular->fresh()->is_admin);
    }

    public function test_create_first_admin_command_creates_only_one_admin_and_hashes_password(): void
    {
        $options = [
            '--first-name' => 'First',
            '--last-name' => 'Administrator',
            '--username' => 'first_admin',
            '--phone' => '5550000',
            '--salary' => '5000.00',
        ];

        $this->artisan('pharmacists:create-first-admin', $options)
            ->expectsQuestion('Password', 'strong-secret-123')
            ->expectsQuestion('Confirm password', 'strong-secret-123')
            ->assertExitCode(0);

        $admin = Pharmacist::where('username', 'first_admin')->firstOrFail();
        $this->assertTrue((bool) $admin->is_admin);
        $this->assertTrue(Hash::check('strong-secret-123', $admin->password));
        $this->assertNotSame('strong-secret-123', $admin->password);

        $this->artisan('pharmacists:create-first-admin', [
            ...$options,
            '--username' => 'another_admin',
            '--phone' => '5550001',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('pharmacists', 1);
    }

    public function test_create_first_admin_command_rejects_invalid_input(): void
    {
        $this->artisan('pharmacists:create-first-admin', [
            '--first-name' => 'First',
            '--last-name' => 'Administrator',
            '--username' => 'first_admin',
            '--phone' => '5550000',
            '--salary' => '-1',
        ])
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertExitCode(1);

        $this->assertDatabaseCount('pharmacists', 0);
    }
}

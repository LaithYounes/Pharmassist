<?php

namespace Tests\Feature;

use App\Models\Pharmacist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PharmacistPasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function pharmacist(bool $isAdmin, string $username): Pharmacist
    {
        return Pharmacist::create([
            'first_name' => 'Test',
            'last_name' => 'Pharmacist',
            'username' => $username,
            'password' => Hash::make('old-password'),
            'phone' => $username.'-phone',
            'employment_date' => now(),
            'salary' => 3000,
            'is_admin' => $isAdmin,
        ]);
    }

    private function adminToken(): string
    {
        $admin = $this->pharmacist(true, 'admin');

        return $this->postJson('/api/Login', [
            'username' => $admin->username,
            'password' => 'old-password',
        ])->assertOk()->json('token');
    }

    public function test_updated_password_works_for_login_and_old_password_fails(): void
    {
        $token = $this->adminToken();
        $pharmacist = $this->pharmacist(false, 'regular');

        $response = $this->withToken($token)->putJson('/api/UpdatePharmacist/'.$pharmacist->id, [
            'first_name' => 'Updated',
            'password' => 'new-password',
        ])->assertOk()
            ->assertJsonPath('pharmacist.first_name', 'Updated')
            ->assertJsonMissingPath('pharmacist.password');

        $this->assertStringNotContainsString('new-password', $response->getContent());
        $this->assertStringNotContainsString($pharmacist->fresh()->password, $response->getContent());

        $this->postJson('/api/Login', [
            'username' => 'regular',
            'password' => 'new-password',
        ])->assertOk()->assertJsonMissingPath('pharmacist.password');

        $this->postJson('/api/Login', [
            'username' => 'regular',
            'password' => 'old-password',
        ])->assertUnauthorized();
    }

    public function test_updating_other_fields_without_password_keeps_existing_password(): void
    {
        $token = $this->adminToken();
        $pharmacist = $this->pharmacist(false, 'regular');
        $originalHash = $pharmacist->password;

        $this->withToken($token)->putJson('/api/UpdatePharmacist/'.$pharmacist->id, [
            'first_name' => 'Updated',
        ])->assertOk()->assertJsonMissingPath('pharmacist.password');

        $this->assertSame($originalHash, $pharmacist->fresh()->password);
        $this->postJson('/api/Login', [
            'username' => 'regular',
            'password' => 'old-password',
        ])->assertOk();
    }

    public function test_empty_password_is_rejected_without_changing_existing_password(): void
    {
        $token = $this->adminToken();
        $pharmacist = $this->pharmacist(false, 'regular');
        $originalHash = $pharmacist->password;

        $this->withToken($token)->putJson('/api/UpdatePharmacist/'.$pharmacist->id, [
            'password' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertSame($originalHash, $pharmacist->fresh()->password);
    }
}

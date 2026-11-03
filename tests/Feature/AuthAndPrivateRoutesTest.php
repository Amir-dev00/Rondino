<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndPrivateRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_private_routes(): void
    {
        $this->get('/listings')->assertRedirect('/login');
        $this->get('/pricing')->assertRedirect('/login');
        $this->get('/deals')->assertRedirect('/login');
        $this->get('/admin/sources')->assertRedirect('/login');
    }

    public function test_no_public_registration_route(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_active_user_can_login_and_see_listings(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password-test-1'),
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password-test-1',
        ])->assertRedirect('/listings');

        $this->actingAs($user)->get('/listings')->assertOk();
        $this->actingAs($user)->get('/admin/sources')->assertForbidden();
    }

    public function test_admin_can_open_source_dashboard(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($admin)->get('/admin/sources')->assertOk();
    }
}

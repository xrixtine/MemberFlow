<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_renders(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get('/profile')->assertOk()->assertSee($user->email);
    }

    public function test_user_can_update_name_and_email(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->put('/profile', ['name' => 'Updated Name', 'email' => 'updated@example.test'])
            ->assertRedirect('/profile')
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('updated@example.test', $user->email);
    }

    public function test_email_must_be_unique_and_valid(): void
    {
        $other = User::factory()->admin()->create();
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->put('/profile', ['name' => 'X', 'email' => $other->email])->assertSessionHasErrors('email');
        $this->actingAs($user)->put('/profile', ['name' => 'X', 'email' => 'not-an-email'])->assertSessionHasErrors('email');
    }

    public function test_profile_update_cannot_change_role_or_active_flag(): void
    {
        $user = User::factory()->member()->create();

        $this->actingAs($user)->put('/profile', [
            'name' => 'Sneaky', 'email' => $user->email, 'role' => 'admin', 'is_active' => 0,
        ]);

        $user->refresh();
        $this->assertTrue($user->isMember());   // members cannot even edit their profile (403); role unchanged
        $this->assertTrue($user->is_active);
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'WrongPassword1',
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ])->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->assertTrue(Hash::check('Password123', $user->fresh()->password));
    }

    public function test_weak_new_password_is_rejected(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'Password123',
            'password' => 'weakpass',
            'password_confirmation' => 'weakpass',
        ])->assertSessionHasErrorsIn('updatePassword', 'password');
    }

    public function test_user_can_change_password_and_sign_in_with_it(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'Password123',
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ])->assertRedirect('/profile')->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('NewPassword456', $user->fresh()->password));
    }
}

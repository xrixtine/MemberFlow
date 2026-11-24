<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_from_protected_pages(): void
    {
        foreach (['/', '/dashboard', '/admin/dashboard', '/profile'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_home_redirects_each_role_to_its_dashboard(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/')->assertRedirect(route('admin.dashboard'));
        $this->actingAs(User::factory()->member()->create())->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_staff_cannot_access_admin_area(): void
    {
        $this->actingAs(User::factory()->member()->create())
            ->get('/admin/dashboard')
            ->assertForbidden()
            ->assertSee('Access denied');
    }

    public function test_admin_cannot_use_the_staff_task_dashboard(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_each_role_can_open_its_own_dashboard(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/dashboard')->assertOk()->assertSee('Total members');

        $this->actingAs(User::factory()->member()->create())
            ->get('/dashboard')->assertOk()->assertSee('My current tasks');
    }

    public function test_every_admin_route_is_protected_by_auth_active_and_admin_role(): void
    {
        $adminRoutes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.'));

        $this->assertNotEmpty($adminRoutes);

        foreach ($adminRoutes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware, $route->getName());
            $this->assertContains('active', $middleware, $route->getName());
            $this->assertContains('role:admin', $middleware, $route->getName());
        }
    }

    public function test_admin_gate_allows_only_active_administrators(): void
    {
        $this->assertTrue(Gate::forUser(User::factory()->admin()->create())->allows('admin'));
        $this->assertFalse(Gate::forUser(User::factory()->member()->create())->allows('admin'));
        $this->assertFalse(Gate::forUser(User::factory()->admin()->inactive()->create())->allows('admin'));
    }

    public function test_sidebar_shows_role_appropriate_navigation(): void
    {
        $this->actingAs(User::factory()->member()->create())
            ->get('/dashboard')
            ->assertSee('My membership')->assertSee(route('my.payments.index'))
            ->assertDontSee(route('admin.dashboard'));

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/dashboard')
            ->assertSee(route('admin.dashboard'))
            ->assertDontSee(route('my.payments.index'));
    }
}

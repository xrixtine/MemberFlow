<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Models\Member;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\Task;
use App\Models\User;
use App\Services\MembershipService;
use App\Support\Money;
use Carbon\Carbon;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\LevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 9: security headers and the optional demonstration seeder.
 */
class FinalHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_web_response_carries_the_security_headers(): void
    {
        foreach (['/login', '/apply'] as $path) {
            $this->get($path)->assertOk()
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeaderMissing('X-Powered-By');
        }

        $this->actingAs(User::factory()->admin()->create())->get('/admin/dashboard')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_token_pages_keep_their_stricter_no_referrer_policy(): void
    {
        $this->get('/invitations/'.str_repeat('a', 64))
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_demo_seeder_adds_consistent_fictional_data_once(): void
    {
        $this->seed([CategorySeeder::class, LevelSeeder::class]);
        User::factory()->admin()->create();

        $this->seed(DemoDataSeeder::class);

        $this->assertSame(12, Member::count());
        $this->assertSame(2, User::members()->count());                    // demo member accounts (no staff since R6)
        $this->assertSame(0, User::whereIn('role', ['staff', 'legacy_staff'])->count());
        $this->assertTrue(Task::pluck('assigned_to')->every(fn ($id) => User::find($id)->isMember()));
        $this->assertSame(5, Task::count());
        $this->assertTrue(Member::pluck('email')->every(fn ($e) => str_ends_with($e, '@example.test')));
        $this->assertSame(5, MembershipTransaction::where('status', TransactionStatus::Approved->value)->count());
        $this->assertSame(1, MembershipTransaction::where('status', TransactionStatus::Expired->value)->count());
        $this->assertSame(1, Payment::whereNotNull('voided_at')->count());

        // Kara: approved ~11 months ago, so her renewal can be demonstrated today.
        $kara = Member::where('first_name', 'Kara')->sole();
        $this->assertSame('active', $kara->status->value);
        $this->assertTrue($kara->membership_end->between(today()->addDays(15), today()->addDays(25)));
        $this->assertNull(app(MembershipService::class)->renewalBlocker($kara));
        // Lito: membership ended last month and was marked Expired by the expiry job.
        $this->assertSame('expired', Member::where('first_name', 'Lito')->sole()->status->value);
        $this->assertNull(Carbon::getTestNow()); // the seeder restored the clock

        // Every cached amount_paid equals the sum of its valid payments.
        MembershipTransaction::all()->each(fn ($t) => $this->assertSame(
            Money::fromDecimal((string) Payment::where('membership_transaction_id', $t->id)->whereNull('voided_at')->sum('amount')),
            Money::fromDecimal($t->amount_paid)));

        // Running it again adds nothing.
        $this->seed(DemoDataSeeder::class);
        $this->assertSame(12, Member::count());
    }

    public function test_demo_seeder_refuses_production_and_needs_an_admin(): void
    {
        $this->seed([CategorySeeder::class, LevelSeeder::class]);

        try {
            $this->app->call([new DemoDataSeeder, 'run']);
            $this->fail('Expected the seeder to refuse without an administrator.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('initial administrator', $e->getMessage());
        }

        User::factory()->admin()->create();
        $this->app['env'] = 'production';
        try {
            $this->app->call([new DemoDataSeeder, 'run']);
            $this->fail('Expected the seeder to refuse in production.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('production', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame(0, Member::count());
    }
}

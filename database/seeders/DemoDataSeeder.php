<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Enums\TaskPriority;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipLevel;
use App\Models\User;
use App\Services\MembershipService;
use App\Services\PaymentService;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OPTIONAL fictional demonstration data (CLI only, never run automatically):
 *
 *   php artisan db:seed --class=DemoDataSeeder
 *
 *  - Refuses to run when APP_ENV=production, when no administrator exists, or when the
 *    demo data was already seeded. Purely additive: nothing is updated or deleted.
 *  - Everything goes through the real services (numbers, fee snapshots, payments,
 *    approvals, task history), so it obeys the same business rules as the UI.
 *  - All people are fictional and use @example.test addresses, so no email is ever
 *    dispatched for them (they are tracked as "skipped").
 *  - Two demo MEMBER accounts (linked to two approved demo members) share one random
 *    password, printed once to this terminal. The demo tasks are assigned to them.
 */
class DemoDataSeeder extends Seeder
{
    /** Email of the first demo member (and its login account); its presence means "already seeded". */
    public const MARKER_EMAIL = 'amelia.demo@example.test';

    /** Marker used by demo data seeded before R6 (staff accounts); also counts as "already seeded". */
    public const LEGACY_MARKER_EMAIL = 'demo.staff1@example.test';

    /** Run $callback with the clock set to $when (Carbon and CarbonImmutable), then restore it. */
    private function travel(\DateTimeInterface $when, callable $callback): void
    {
        [$previous, $previousImmutable] = [Carbon::getTestNow(), CarbonImmutable::getTestNow()];
        $at = Carbon::instance($when);
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at->toImmutable());
        try {
            $callback();
        } finally {
            Carbon::setTestNow($previous);
            CarbonImmutable::setTestNow($previousImmutable);
        }
    }

    public function run(MembershipService $memberships, PaymentService $payments, TaskService $tasks): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoDataSeeder refuses to run in production.');
        }
        $admin = User::admins()->active()->orderBy('id')->first();
        if (! $admin) {
            throw new RuntimeException('Create the initial administrator first (php artisan db:seed --class=UserSeeder).');
        }
        if (User::whereIn('email', [self::MARKER_EMAIL, self::LEGACY_MARKER_EMAIL])->exists()
            || Member::withTrashed()->where('email', self::MARKER_EMAIL)->exists()) {
            $this->command?->warn('Demo data already exists — nothing was added.');

            return;
        }

        $categories = MembershipCategory::where('is_active', true)->orderBy('id')->get();
        $levels = MembershipLevel::where('is_active', true)->ordered()->get();
        if ($categories->isEmpty() || $levels->isEmpty()) {
            throw new RuntimeException('Seed the categories and levels first (CategorySeeder, LevelSeeder).');
        }

        // ---- Members: [first, last, category#, level#, scenario] -----------------
        $people = [
            ['Amelia', 'Demo-Reyes', 0, 0, 'approved'], ['Bruno', 'Demo-Santos', 1, 1, 'approved'],
            ['Cecilia', 'Demo-Garcia', 2, 2, 'approved'], ['Dario', 'Demo-Mendoza', 3, 3, 'partial'],
            ['Elena', 'Demo-Torres', 1, 2, 'paid'], ['Felix', 'Demo-Ramos', 0, 1, 'unpaid'],
            ['Gloria', 'Demo-Flores', 2, 0, 'voided'], ['Hector', 'Demo-Villanueva', 3, 2, 'approved'],
            ['Isabel', 'Demo-Navarro', 1, 3, 'unpaid'], ['Julio', 'Demo-Castillo', 0, 2, 'paid'],
        ];

        foreach ($people as $i => [$first, $last, $cat, $lvl, $scenario]) {
            $member = $memberships->register([
                'first_name' => $first, 'last_name' => $last,
                'email' => strtolower($first).'.demo@example.test',
                'phone' => '0917 000 '.str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT),
                'address' => ($i + 1).' Sample Street, Quezon City (fictional)',
                'membership_category_id' => $categories[$cat % $categories->count()]->id,
                'membership_level_id' => $levels[$lvl % $levels->count()]->id,
            ], $admin);

            $txn = $member->transactions()->first();
            $pay = fn (string $amount, PaymentMethod $method, int $daysAgo) => $payments->record($txn->fresh(), [
                'amount' => $amount, 'method' => $method->value, 'paid_at' => now()->subDays($daysAgo)->format('Y-m-d'),
                'idempotency_key' => (string) Str::uuid(), 'notes' => 'Demo payment',
            ], $admin)->payment;

            $fee = $txn->amount;
            match ($scenario) {
                'approved' => (function () use ($pay, $fee, $memberships, $txn, $admin, $i) {
                    $pay($fee, PaymentMethod::cases()[$i % 3], $i + 1);
                    $memberships->approve($txn->fresh(), $admin);
                })(),
                'paid' => $pay($fee, PaymentMethod::GCash, 2),
                'partial' => $pay(number_format((float) $fee / 2, 2, '.', ''), PaymentMethod::Cash, 3),
                'voided' => $payments->void($pay($fee, PaymentMethod::BankTransfer, 4), $admin, 'Demo: recorded against the wrong member'),
                default => null,
            };
        }

        // ---- Members with real history in the past -------------------------------------
        // The services run with the clock temporarily set back, so every row (payment,
        // approval, validity period) is created exactly as it would have been back then.
        //  - Kara: approved ~11 months 10 days ago → expires in ~20 days → renewal window open.
        //  - Lito: approved 13 months ago → membership ended last month → marked Expired below.
        $past = [
            ['Kara', 'Demo-Expiring', now()->subMonthsNoOverflow(11)->subDays(10)],
            ['Lito', 'Demo-Expired', now()->subMonthsNoOverflow(13)],
        ];
        foreach ($past as $i => [$first, $last, $when]) {
            $this->travel($when, function () use ($memberships, $payments, $admin, $categories, $levels, $first, $last, $i) {
                $member = $memberships->register([
                    'first_name' => $first, 'last_name' => $last, 'email' => strtolower($first).'.demo@example.test',
                    'membership_category_id' => $categories[$i % $categories->count()]->id,
                    'membership_level_id' => $levels[1 % $levels->count()]->id,
                ], $admin);
                $txn = $member->transactions()->first();
                $payments->record($txn, ['amount' => $txn->amount, 'method' => PaymentMethod::Cash->value,
                    'paid_at' => now()->format('Y-m-d'), 'idempotency_key' => (string) Str::uuid(), 'notes' => 'Demo payment'], $admin);
                $memberships->approve($txn->fresh(), $admin);
            });
        }
        $memberships->expireDue(); // the same job the scheduler runs daily

        // ---- Member login accounts (two approved, active demo members) --------------------
        $password = 'Demo'.Str::random(8).'7';
        $accounts = Member::whereIn('email', [self::MARKER_EMAIL, 'bruno.demo@example.test'])->orderBy('email')->get()
            ->map(function (Member $member) use ($password, $admin) {
                $user = new User(['name' => $member->fullName(), 'email' => $member->email, 'password' => $password]);
                $user->forceFill(['role' => UserRole::Member, 'member_id' => $member->id, 'is_active' => true,
                    'email_verified_at' => now(), 'invited_by' => $admin->id])->save();

                return $user;
            })->values();

        // ---- Tasks ----------------------------------------------------------------
        $demoTasks = [
            ['Call applicants with unpaid balances', TaskPriority::High, 2, 0, ['status' => 'in_progress', 'progress' => 40, 'note' => 'Called 2 of 5 so far']],
            ['Prepare membership cards for approved members', TaskPriority::Medium, 5, 1, null],
            ['Update the renewal reminder list', TaskPriority::Low, -1, 0, null],
            ['Confirm GCash references from last week', TaskPriority::Urgent, 1, 1, ['status' => 'on_hold', 'progress' => 20, 'note' => 'Waiting for the bank statement']],
            ['Archive old paper application forms', TaskPriority::Low, 7, 0, ['status' => 'completed']],
        ];
        foreach ($demoTasks as [$title, $priority, $dueIn, $who, $progress]) {
            $task = $tasks->create(['title' => $title, 'description' => 'Fictional demonstration task.', 'priority' => $priority->value,
                'due_date' => now()->addDays($dueIn)->format('Y-m-d')], $accounts[$who], $admin);
            if ($progress) {
                $tasks->recordProgress($task, $accounts[$who], $progress);
            }
        }

        $this->command?->info('Demo data added: 12 fictional members (one renewable, one expired), 2 demo member accounts, 5 tasks.');
        $this->command?->warn('Demo member sign-in (fictional accounts, shown once): '.self::MARKER_EMAIL.' / bruno.demo@example.test — password: '.$password);
    }
}

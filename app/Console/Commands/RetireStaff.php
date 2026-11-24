<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StaffRetirementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * R6 — retire the staff role. DRY RUN by default; nothing changes without --execute.
 *
 *   php artisan memberflow:retire-staff --link=3:MF-2026-00002 --retire=2 --allow-name-mismatch=3
 *   php artisan memberflow:retire-staff --link=3:MF-2026-00002 --retire=2 --allow-name-mismatch=3 --execute
 *   php artisan memberflow:retire-staff --link=3:MF-2026-00002 --retire=2 --revert            (dry run)
 *   php artisan memberflow:retire-staff --link=3:MF-2026-00002 --retire=2 --revert --execute
 *
 * Never prints passwords, password hashes, tokens or full email addresses.
 */
class RetireStaff extends Command
{
    protected $signature = 'memberflow:retire-staff
        {--link=* : USER_ID:MEMBERSHIP_NO — convert this staff account into the member account of that member}
        {--retire=* : USER_ID — retire this staff account (legacy_staff: kept for history, cannot sign in)}
        {--allow-name-mismatch=* : USER_ID — confirm linking although the user and member names differ}
        {--revert : Undo a previous run for the listed accounts}
        {--execute : Apply the changes (without it this is a dry run)}';

    protected $description = 'Retire the staff role: link staff accounts to member records or retire them (dry run by default)';

    public function handle(StaffRetirementService $service): int
    {
        $links = [];
        foreach ((array) $this->option('link') as $pair) {
            if (! preg_match('/^(\d+):(MF-\d{4}-\d{5})$/', (string) $pair, $m)) {
                $this->error("Invalid --link value \"{$pair}\". Use USER_ID:MEMBERSHIP_NO, e.g. 3:MF-2026-00002.");

                return self::INVALID;
            }
            $links[(int) $m[1]] = $m[2];
        }
        $ids = fn (string $option) => array_map('intval', array_filter((array) $this->option($option), fn ($v) => ctype_digit((string) $v)));
        $retire = $ids('retire');
        $allow = $ids('allow-name-mismatch');
        if (count($retire) !== count((array) $this->option('retire')) || count($allow) !== count((array) $this->option('allow-name-mismatch'))) {
            $this->error('--retire and --allow-name-mismatch take numeric user IDs.');

            return self::INVALID;
        }
        if ($links === [] && $retire === []) {
            $this->error('Nothing to do: name the accounts with --link and/or --retire.');

            return self::INVALID;
        }

        $revert = (bool) $this->option('revert');
        $execute = (bool) $this->option('execute');
        $this->line(sprintf('Database: %s · Mode: %s%s', DB::connection()->getDatabaseName(), $revert ? 'REVERT' : 'MIGRATE', $execute ? ' (EXECUTE)' : ' (dry run — nothing will change)'));

        $plan = $revert ? $service->revertPlan($links, $retire) : $service->plan($links, $retire, $allow);
        $this->report($plan, $revert);

        if ($plan['errors']) {
            $this->newLine();
            $this->error('Refused — nothing was changed:');
            foreach ($plan['errors'] as $error) {
                $this->line('  • '.$error);
            }

            return self::FAILURE;
        }
        if ($plan['changes'] === []) {
            $this->info('Nothing to do: every listed account is already in the requested state.');

            return self::SUCCESS;
        }
        if (! $execute) {
            $this->warn('DRY RUN — nothing was changed. Re-run with --execute to apply these '.count($plan['changes']).' change(s).');

            return self::SUCCESS;
        }

        try {
            $applied = $revert ? $service->revert($links, $retire) : $service->execute($links, $retire, $allow);
        } catch (Throwable $e) {
            $this->error('Refused at execution (re-checked under row locks) — nothing was changed:');
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        $this->info(count($applied).' change(s) applied and recorded in the account audit trail.');
        if ($revert) {
            $this->warn('Note: the staff role cannot sign in with the current application code. Restore the pre-R6 code as well if staff access is needed again.');
        }

        return self::SUCCESS;
    }

    private function report(array $plan, bool $revert): void
    {
        $rows = [];
        foreach (['changes' => 'WILL CHANGE', 'done' => 'already done'] as $key => $state) {
            foreach ($plan[$key] as $row) {
                /** @var User $user */
                $user = $row['user'];
                $target = match (true) {
                    $revert => 'staff (member link removed)',
                    $row['action'] === 'link' => 'member → '.$row['member']->membership_no.' ('.$row['member']->fullName().')',
                    default => 'legacy_staff (cannot sign in)',
                };
                $rows[] = [$state, '#'.$user->id, $user->name, $this->mask($user->email), $user->role->value, $target,
                    ($row['name_mismatch'] ?? false) ? 'names differ (confirmed)' : ''];
            }
        }
        if ($rows) {
            $this->table(['State', 'User', 'Name', 'Email', 'Current role', 'New state', 'Note'], $rows);
        }
    }

    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 3).'***@'.$domain;
    }
}

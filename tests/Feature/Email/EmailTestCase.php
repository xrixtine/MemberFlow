<?php

namespace Tests\Feature\Email;

use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipLevel;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\InvitationService;
use App\Support\FormTimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared fixtures for public application and email tests.
 *
 * Recipients on @example.test are "fictional" and skipped by design. Tests that need
 * an email to actually go through the mailer use REAL_DOMAIN instead — tests run
 * with MAIL_MAILER=array (in memory), so nothing is ever sent over the network.
 */
abstract class EmailTestCase extends TestCase
{
    use RefreshDatabase;

    public const REAL_DOMAIN = 'inbox.memberflow.dev';

    protected User $admin;

    protected MembershipCategory $category;

    protected MembershipLevel $gold;

    protected MembershipLevel $free;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(10, 0));

        $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'email' => self::email('ada.admin')]);
        $this->category = MembershipCategory::factory()->create(['name' => 'Regular']);
        $this->gold = MembershipLevel::factory()->create(['name' => 'Gold', 'fee' => '2000.00', 'validity_months' => 12, 'benefits' => "Priority support\nEvent discounts"]);
        $this->free = MembershipLevel::factory()->create(['name' => 'Community', 'fee' => '0.00', 'validity_months' => 6]);
    }

    protected static function email(string $local): string
    {
        return $local.'@'.self::REAL_DOMAIN;
    }

    /**
     * Issue a member-account activation invitation (the only kind since R6) to a new
     * member with this address. Returns [invitation, raw token].
     *
     * @return array{0: UserInvitation, 1: string}
     */
    protected function inviteMember(string $email, string $name = 'Invited Member'): array
    {
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, 'Member');
        $member = Member::factory()->for($this->category, 'category')->for($this->gold, 'level')
            ->create(['first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'email' => $email]);

        return app(InvitationService::class)->issueForMember($member, $this->admin);
    }

    /** A valid public application payload with a form token issued 10 seconds ago. */
    protected function applicationPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Lea',
            'middle_name' => '',
            'last_name' => 'Santos',
            'email' => self::email('lea.santos'),
            'phone' => '0917 555 0101',
            'birthdate' => '1995-04-10',
            'address' => '12 Mabini St, Manila',
            'membership_category_id' => $this->category->id,
            'membership_level_id' => $this->gold->id,
            'privacy_consent' => '1',
            'form_token' => FormTimer::issue(time() - 10),
            'website' => '',
        ], $overrides);
    }
}

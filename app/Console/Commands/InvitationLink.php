<?php

namespace App\Console\Commands;

use App\Enums\InvitationStatus;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\InvitationService;
use App\Support\AppUrl;
use Illuminate\Console\Command;

/**
 * DEVELOPMENT-ONLY way to test invitation acceptance without a real inbox
 * (e.g. with MAIL_MAILER=log, where links are removed from the mail log).
 *
 * Raw tokens are never stored, so an old link cannot be recovered. This
 * command therefore RESENDS the invitation (rotating its token, which
 * invalidates any earlier link; the resend also queues a normal invitation
 * email) and prints the new link to this terminal only. Nothing is logged or
 * shown in the web UI. It refuses to run in production.
 */
class InvitationLink extends Command
{
    protected $signature = 'memberflow:invitation-link {email : The invited email address}';

    protected $description = '[Local development only] Rotate a pending invitation\'s token and print its activation link';

    public function handle(InvitationService $invitations): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This command only runs in the local or testing environment.');

            return self::FAILURE;
        }

        $invitation = UserInvitation::where('email', strtolower(trim($this->argument('email'))))
            ->whereIn('status', [InvitationStatus::Pending->value, InvitationStatus::Expired->value])
            ->latest('id')
            ->first();

        if (! $invitation) {
            $this->error('No pending or expired invitation was found for that email address.');

            return self::FAILURE;
        }

        $actor = User::find($invitation->invited_by);
        $invitation->forceFill(['last_sent_at' => now()->subMinutes(5)])->save(); // bypass the UI resend cool-down
        $token = $invitations->resend($invitation, $actor);

        $this->warn('Any previous link for this invitation no longer works.');
        $this->line('Activation link (valid for '.$invitations->ttlHours().' hours, single use):');
        $this->line(AppUrl::route('invitation.show', $token));

        return self::SUCCESS;
    }
}

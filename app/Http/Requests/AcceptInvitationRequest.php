<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Services\InvitationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Public form: the invitee chooses their password. The token itself is
 * validated by InvitationService under a row lock.
 *
 * Invitees do not choose a name: a member account's name comes from the
 * membership record (administrator-managed).
 * The role is NEVER read from the form; it comes from the stored invitation.
 */
class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $invitation = app(InvitationService::class)->findByToken((string) $this->route('token'));

        return [
            // Member names come from the membership record. Unknown/dead or retired staff
            // tokens skip the rule so the service shows the "invalid link" page instead.
            'name' => $invitation?->role === UserRole::Member ? ['prohibited'] : ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return ['name.prohibited' => 'Your name comes from your membership record and cannot be changed here.'];
    }
}

<?php

namespace App\Http\Requests;

use App\Models\Member;

class StoreMemberRequest extends MemberRequest
{
    protected function member(): ?Member
    {
        return null;
    }

    public function rules(): array
    {
        return parent::rules() + [
            // Default on: registration opens a pending application transaction.
            'create_application' => ['sometimes', 'boolean'],
        ];
    }

    public function wantsApplication(): bool
    {
        return $this->boolean('create_application');
    }
}

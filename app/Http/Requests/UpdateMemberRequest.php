<?php

namespace App\Http\Requests;

use App\Models\Member;

class UpdateMemberRequest extends MemberRequest
{
    protected function member(): ?Member
    {
        return $this->route('member');
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * A member account's name and login email come from its membership record
     * (kept in step by an administrator), so members cannot change them here.
     * Only name and email are ever validated: a role, member_id or is_active
     * sent with the request is ignored (and they are not mass assignable).
     */
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->isMember();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
        ];
    }
}

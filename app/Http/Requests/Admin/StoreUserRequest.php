<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user || ! $user->isAdmin()) {
            return false;
        }

        // Only Central QA can create an admin account or grant central QA privileges
        if ($this->input('role') === UserRole::Admin->value || $this->input('role') === 'admin') {
            if (! $user->isCentralQa()) {
                return false;
            }
        }

        // Delegated admin cannot create accounts outside their department scope
        if (! $user->isCentralQa()) {
            if ($this->filled('department_id') && (int) $this->input('department_id') !== (int) $user->department_id) {
                return false;
            }
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

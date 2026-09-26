<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $caller = $this->user();
        if (! $caller || ! $caller->isAdmin()) {
            return false;
        }

        if ($caller->isCentralQa()) {
            return true;
        }

        $target = $this->route('user');
        if (! $target instanceof \App\Models\User) {
            $target = \App\Models\User::find($target);
        }

        if (! $target) {
            return true;
        }

        // Delegated admin cannot modify any admin user
        if ($target->isAdmin()) {
            return false;
        }

        // Delegated admin cannot modify users outside their department
        if ((int) $target->department_id !== (int) $caller->department_id) {
            return false;
        }

        // Delegated admin cannot promote to admin
        if ($this->filled('role') && ($this->input('role') === UserRole::Admin->value || $this->input('role') === 'admin')) {
            return false;
        }

        // Delegated admin cannot reassign user away from their department
        if ($this->filled('department_id') && (int) $this->input('department_id') !== (int) $caller->department_id) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'password' => ['sometimes', 'string', 'min:8'],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

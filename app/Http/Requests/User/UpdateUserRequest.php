<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $userId = $user instanceof User ? $user->id : $user;

        return [
            'full_name'     => 'required|string|max:255',
            'username'      => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($userId),
            ],
            'email'         => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password'      => ['nullable', 'confirmed', Password::defaults()],
            'roles'         => 'required|array',
            'roles.*'       => 'string|exists:roles,name,guard_name,api',
            'type'          => 'required|string|in:regular,designer,technician',

            // التحقق من صحة وجود المعرفات عند التحديث
            'store_id'      => 'nullable|exists:stores,id',
            'treasury_id'   => 'nullable|exists:treasuries,id',
            'bank_id'       => 'nullable|exists:banks,id',

            // ضبط التحكم بالمخزون
            'stock_control' => 'nullable|boolean',
        ];
    }
}
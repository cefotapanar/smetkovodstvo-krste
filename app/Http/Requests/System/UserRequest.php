<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `super` на рутата
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('user')?->id);
    }

    /** Статички — ги користи и `public/sistem.php` за првиот администратор. */
    public static function rulesFor(?int $ignoreId = null): array
    {
        return [
            'name'             => ['required', 'string', 'max:255'],
            'email'            => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignoreId)],
            // При измена празна лозинка = не се менува.
            'password'         => [$ignoreId ? 'nullable' : 'required', 'string', 'min:10'],
            'is_super'         => ['nullable', 'boolean'],
            'is_active'        => ['nullable', 'boolean'],
            'firms'            => ['nullable', 'array'],
            'firms.*.firm_id'  => ['required', 'integer', 'distinct', 'exists:firms,id'],
            'firms.*.role_id'  => ['nullable', 'integer', 'exists:roles,id'],
        ];
    }

    public function attributes(): array
    {
        return self::names();
    }

    public static function names(): array
    {
        return [
            'name' => 'име', 'email' => 'е-пошта', 'password' => 'лозинка',
            'is_super' => 'главен администратор', 'is_active' => 'активен',
            'firms' => 'фирми', 'firms.*.firm_id' => 'фирма', 'firms.*.role_id' => 'улога',
        ];
    }
}

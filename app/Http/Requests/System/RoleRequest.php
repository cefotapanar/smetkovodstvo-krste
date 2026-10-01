<?php

namespace App\Http\Requests\System;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `super` на рутата
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($this->route('role')?->id)],
            'permissions'   => ['nullable', 'array'],
            // Непознат клуч се одбива, не се прескокнува: тивко изгубено право
            // значи „ја зачував улогата, а сметководителот пак не може да книжи“.
            'permissions.*' => ['string', Rule::in(Role::allKeys())],
        ];
    }

    public function messages(): array
    {
        return ['permissions.*.in' => 'Непозната дозвола: :input.'];
    }

    public function attributes(): array
    {
        return ['name' => 'име', 'permissions' => 'дозволи'];
    }
}

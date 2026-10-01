<?php

namespace App\Http\Requests\Ledger;

use Illuminate\Foundation\Http\FormRequest;

/** Ново конто бара шифра и име; измената — ништо задолжително (шифрата не се менува). */
class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $new = $this->isMethod('post');

        return [
            'code'              => $new ? ['required', 'regex:/^\d{4,8}$/'] : ['prohibited'],
            'name'              => [$new ? 'required' : 'nullable', 'string', 'max:255'],
            'needs_partner'     => ['nullable', 'boolean'],
            'needs_cost_center' => ['nullable', 'boolean'],
            'is_active'         => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex'      => 'Аналитичкото конто има 4 до 8 цифри.',
            'code.prohibited' => 'Шифрата на конто не се менува — отворете ново.',
        ];
    }

    public function attributes(): array
    {
        return ['code' => 'шифра', 'name' => 'име'];
    }
}

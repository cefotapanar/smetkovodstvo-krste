<?php

namespace App\Http\Requests\System;

use App\Models\Firm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `super` на рутата
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('firm')?->id);
    }

    /**
     * Статички за да ги користи и `public/sistem.php` (прва фирма), без втор
     * список правила што би се разминал.
     */
    public static function rulesFor(?int $ignoreId = null): array
    {
        return [
            'name'          => ['required', 'string', 'max:255'],
            'short_name'    => ['nullable', 'string', 'max:60'],
            'tax_id'        => ['required', 'regex:/^\d{13}$/', Rule::unique('firms', 'tax_id')->ignore($ignoreId)],
            'reg_no'        => ['nullable', 'string', 'max:20'],
            'activity_code' => ['nullable', 'string', 'max:10'],
            'size'          => ['nullable', Rule::in(Firm::SIZES)],
            'vat_period'    => ['required', Rule::in(Firm::VAT_PERIODS)],
            'address'       => ['nullable', 'string', 'max:255'],
            'is_active'     => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['tax_id.regex' => 'ЕДБ мора да има точно 13 цифри.'];
    }

    public function attributes(): array
    {
        return self::names();
    }

    public static function names(): array
    {
        return [
            'name' => 'име', 'short_name' => 'кратко име', 'tax_id' => 'ЕДБ', 'reg_no' => 'ЕМБС',
            'activity_code' => 'дејност (НКД)', 'size' => 'големина', 'vat_period' => 'ДДВ период',
            'address' => 'адреса', 'is_active' => 'активна',
        ];
    }
}

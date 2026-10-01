<?php

namespace App\Http\Requests\Ledger;

use App\Models\Firm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:255'],
            // Ист ЕДБ двапати во иста фирма = две картички за еден партнер, а ИОС
            // и отворените ставки тогаш не се совпаѓаат со неговите.
            'tax_id'    => ['nullable', 'regex:/^\d{13}$/', Rule::unique('partners', 'tax_id')
                ->where('firm_id', Firm::current()->id)->ignore($this->route('id'))],
            'address'   => ['nullable', 'string', 'max:255'],
            'city'      => ['nullable', 'string', 'max:100'],
            'country'   => ['nullable', 'string', 'size:2', 'alpha'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['tax_id.regex' => 'ЕДБ мора да има точно 13 цифри.', 'tax_id.unique' => 'Партнер со овој ЕДБ веќе постои.'];
    }

    public function attributes(): array
    {
        return ['name' => 'име', 'tax_id' => 'ЕДБ', 'address' => 'адреса', 'city' => 'град', 'country' => 'земја', 'is_active' => 'активен'];
    }
}

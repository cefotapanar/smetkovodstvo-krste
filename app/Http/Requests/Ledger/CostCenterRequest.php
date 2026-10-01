<?php

namespace App\Http\Requests\Ledger;

use App\Models\Firm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CostCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code'      => ['required', 'string', 'max:20', Rule::unique('cost_centers', 'code')
                ->where('firm_id', Firm::current()->id)->ignore($this->route('id'))],
            'name'      => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['code' => 'шифра', 'name' => 'име', 'is_active' => 'активно'];
    }
}

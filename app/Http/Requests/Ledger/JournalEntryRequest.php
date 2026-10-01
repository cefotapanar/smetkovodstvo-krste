<?php

namespace App\Http\Requests\Ledger;

use App\Models\Firm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Облик на налогот. Правилата на КНИЖЕЊЕТО (рамнотежа, конто за книжење,
 * партнер, заклучен период) се во `EntryRules` — нацрт смее да е недовршен.
 * Тука само: дека сè што е пратено постои и е од ОВАА фирма.
 */
class JournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $firm = Firm::current()->id;
        $own = fn (string $table) => Rule::exists($table, 'id')->where('firm_id', $firm);
        $money = ['nullable', 'numeric', 'between:-9999999999999.99,9999999999999.99', 'decimal:0,2'];

        return [
            'journal_type_id'        => ['required', 'integer', $own('journal_types')],
            'date'                   => ['required', 'date_format:Y-m-d'],
            'description'            => ['nullable', 'string', 'max:500'],
            'post'                   => ['nullable', 'boolean'],
            'lines'                  => ['nullable', 'array', 'max:2000'],
            'lines.*.account_id'     => ['required', 'integer', $own('accounts')],
            'lines.*.partner_id'     => ['nullable', 'integer', $own('partners')],
            'lines.*.cost_center_id' => ['nullable', 'integer', $own('cost_centers')],
            'lines.*.doc_number'     => ['nullable', 'string', 'max:50'],
            'lines.*.doc_date'       => ['nullable', 'date_format:Y-m-d'],
            'lines.*.due_date'       => ['nullable', 'date_format:Y-m-d'],
            'lines.*.debit'          => $money,
            'lines.*.credit'         => $money,
            'lines.*.description'    => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.*.debit.decimal'  => 'Износот може да има најмногу две децимали.',
            'lines.*.credit.decimal' => 'Износот може да има најмногу две децимали.',
            'date.date_format'       => 'Датумот мора да е во облик ГГГГ-ММ-ДД.',
        ];
    }

    public function attributes(): array
    {
        return [
            'journal_type_id' => 'вид на налог', 'date' => 'датум', 'description' => 'опис', 'lines' => 'ставки',
            'lines.*.account_id' => 'конто', 'lines.*.partner_id' => 'партнер', 'lines.*.cost_center_id' => 'место на трошок',
            'lines.*.doc_number' => 'документ', 'lines.*.doc_date' => 'датум на документ', 'lines.*.due_date' => 'валута',
            'lines.*.debit' => 'должи', 'lines.*.credit' => 'побарува', 'lines.*.description' => 'опис на ставка',
        ];
    }
}

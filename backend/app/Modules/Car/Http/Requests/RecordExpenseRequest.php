<?php

namespace App\Modules\Car\Http\Requests;

use App\Modules\Shared\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordExpense', $this->route('car'));
    }

    public function rules(): array
    {
        return [
            'expense_type_id' => ['required', 'string', Rule::exists('car_expense_types', 'id')->where('is_active', true)],
            'expense_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1990-01-01', 'before_or_equal:today'],
            'amount' => ['required', new MoneyAmount],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}

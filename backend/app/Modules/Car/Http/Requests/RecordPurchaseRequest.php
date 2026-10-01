<?php

namespace App\Modules\Car\Http\Requests;

use App\Modules\Shared\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordPurchase', $this->route('car'));
    }

    public function rules(): array
    {
        return [
            'dealer_id' => ['required', 'string', Rule::exists('car_dealers', 'id')->where('is_active', true)],
            'purchase_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1990-01-01', 'before_or_equal:today'],
            'amount' => ['required', new MoneyAmount],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}

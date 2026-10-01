<?php

namespace App\Modules\Car\Http\Requests;

use App\Modules\Car\Enums\PaymentMethod;
use App\Modules\Shared\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Party payments (POST /cars/{car}/party-payments) and dealer payments (POST /cars/{car}/dealer-payments).
 */
class RecordPaymentRequest extends FormRequest
{
    public function kind(): string
    {
        return $this->routeIs('cars.party-payments.store') ? 'party' : 'dealer';
    }

    public function authorize(): bool
    {
        return $this->user()->can($this->kind() === 'party' ? 'recordPartyPayment' : 'recordDealerPayment', $this->route('car'));
    }

    public function rules(): array
    {
        return [
            'payment_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1990-01-01', 'before_or_equal:today'],
            'amount' => ['required', new MoneyAmount],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}

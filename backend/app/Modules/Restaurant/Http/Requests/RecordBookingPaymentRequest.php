<?php

namespace App\Modules\Restaurant\Http\Requests;

use App\Modules\Restaurant\Enums\PaymentMethod;
use App\Modules\Shared\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordBookingPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordPayment', $this->route('booking'));
    }

    public function rules(): array
    {
        return [
            'payment_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today'],
            'amount' => ['required', new MoneyAmount],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}

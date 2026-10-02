<?php

namespace App\Modules\Restaurant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reversing an expense or one of its supplier payments: a reason is mandatory (audit trail).
 */
class ReverseRestaurantExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('payment') !== null ? 'reversePayment' : 'reverse', $this->route('expense'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}

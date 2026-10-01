<?php

namespace App\Modules\Car\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reason is mandatory: reversals are part of the financial audit trail.
 */
class ReverseRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = match (true) {
            $this->route('purchase') !== null => 'reversePurchase',
            $this->route('expense') !== null => 'reverseExpense',
            $this->route('sale') !== null => 'reverseSale',
            $this->route('partyPayment') !== null => 'reversePartyPayment',
            default => 'reverseDealerPayment',
        };

        return $this->user()->can($ability, $this->route('car'));
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

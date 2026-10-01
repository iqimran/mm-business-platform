<?php

namespace App\Modules\Restaurant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reason is mandatory: reversals are part of the financial audit trail.
 */
class ReverseSaleRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('payment') !== null ? 'reversePayment' : 'reverse', $this->route('sale'));
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

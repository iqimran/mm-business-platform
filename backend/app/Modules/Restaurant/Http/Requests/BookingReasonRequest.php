<?php

namespace App\Modules\Restaurant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelling a booking or reversing a booking payment: a reason is mandatory (audit trail).
 */
class BookingReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('payment') !== null ? 'reversePayment' : 'cancel', $this->route('booking'));
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

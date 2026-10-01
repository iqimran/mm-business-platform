<?php

namespace App\Modules\Restaurant\Http\Requests;

use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Shared\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Changes to a confirmed booking. Payments are separate actions; status changes use cancel/complete.
 */
class UpdateHallBookingRequest extends FormRequest
{
    private function booking(): HallBooking
    {
        return $this->route('booking');
    }

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->booking());
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('notes'))) {
            $this->merge(['notes' => trim($this->input('notes')) ?: null]);
        }
    }

    public function rules(): array
    {
        $booking = $this->booking();
        $customerChanges = $this->input('customer_id') !== $booking->customer_id;
        $dateChanges = $this->input('booking_date') !== $booking->booking_date->toDateString();

        return [
            'hall_id' => ['sometimes', 'string', Rule::exists('restaurant_halls', 'id')->where('branch_id', $booking->branch_id)],
            'customer_id' => ['sometimes', 'string', Rule::exists('restaurant_customers', 'id')
                ->when($customerChanges, fn ($rule) => $rule->where('is_active', true))],
            // A booking can keep a past date, but cannot be moved into the past.
            'booking_date' => ['sometimes', 'date_format:Y-m-d', ...($dateChanges ? ['after_or_equal:today'] : []),
                'before_or_equal:'.now()->addYears(5)->toDateString()],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i'],
            'agreed_amount' => ['sometimes', new MoneyAmount],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['start_time', 'end_time'])) {
                return;
            }
            $start = $this->input('start_time', $this->booking()->startsAt());
            $end = $this->input('end_time', $this->booking()->endsAt());
            if ($end <= $start) {
                $validator->errors()->add('end_time', 'The end time must be after the start time.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'hall_id.exists' => 'Select a hall of the same branch.',
            'customer_id.exists' => 'Select an active customer.',
            'booking_date.after_or_equal' => 'The booking date cannot be in the past.',
        ];
    }
}

<?php

namespace App\Modules\Restaurant\Http\Requests;

use App\Modules\Branch\Models\Branch;
use App\Modules\Restaurant\Enums\PaymentMethod;
use App\Modules\Restaurant\Http\Requests\Concerns\ValidatesFoodPackage;
use App\Modules\Restaurant\Models\Hall;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Shared\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * New hall booking. The branch is the hall's branch (never taken from the client),
 * so the user must have access to that branch.
 */
class StoreHallBookingRequest extends FormRequest
{
    use ValidatesFoodPackage;

    public function authorize(): bool
    {
        return $this->user()->can('create', HallBooking::class);
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeBookingAmounts();
        if (is_string($this->input('notes'))) {
            $this->merge(['notes' => trim($this->input('notes')) ?: null]);
        }
    }

    public function rules(): array
    {
        return [
            'hall_id' => ['required', 'string', Rule::exists('restaurant_halls', 'id')->where('is_active', true)],
            'customer_id' => ['required', 'string', Rule::exists('restaurant_customers', 'id')->where('is_active', true)],
            'booking_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addYears(5)->toDateString()],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'hall_charge' => ['required', new MoneyAmount(allowZero: true)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'payment' => ['sometimes', 'nullable', 'array'],
            'payment.amount' => ['required_with:payment', new MoneyAmount],
            'payment.method' => ['required_with:payment', Rule::enum(PaymentMethod::class)],
            'payment.reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            ...$this->foodPackageRules(),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('hall_id')) {
                return;
            }
            // Unknown and inaccessible halls look the same (existence is not leaked). Like sales and expenses,
            // new bookings need an active branch (users with global access can still see inactive branches).
            $branchId = Hall::whereKey($this->input('hall_id'))->value('branch_id');
            if (! $this->user()->canAccessBranch($branchId) || ! Branch::whereKey($branchId)->where('is_active', true)->exists()) {
                $validator->errors()->add('hall_id', 'Select an active hall.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'hall_id.exists' => 'Select an active hall.',
            'customer_id.exists' => 'Select an active customer.',
            'booking_date.after_or_equal' => 'The booking date cannot be in the past.',
            'end_time.after' => 'The end time must be after the start time.',
            ...$this->foodPackageMessages(),
        ];
    }
}

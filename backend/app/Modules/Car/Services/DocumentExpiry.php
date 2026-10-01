<?php

namespace App\Modules\Car\Services;

use App\Modules\Administration\Models\Setting;
use App\Modules\Car\Models\CarDocument;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Builder;

/**
 * Expiry status of car documents:
 * - expired:  expiry date before today
 * - expiring: expiry within the alert window (setting "car.document_alert_days", default 30)
 * - valid:    otherwise
 * Superseded (renewed) documents never raise alerts.
 * Scoped: one instance per request, so the setting is read once.
 */
#[Scoped]
class DocumentExpiry
{
    private ?int $alertDays = null;

    public const SETTING_KEY = 'car.document_alert_days';

    public const DEFAULT_ALERT_DAYS = 30;

    public function alertDays(): int
    {
        if ($this->alertDays === null) {
            $value = Setting::where('key', self::SETTING_KEY)->first()?->value;
            $this->alertDays = is_int($value) && $value >= 1 && $value <= 365 ? $value : self::DEFAULT_ALERT_DAYS;
        }

        return $this->alertDays;
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::today();
    }

    /**
     * @return array{status: string, days_remaining: int}
     */
    public function evaluate(CarDocument $document): array
    {
        $days = (int) $this->today()->diffInDays($document->expiry_date->toImmutable()->startOfDay(), false);

        $status = match (true) {
            $days < 0 => 'expired',
            $days <= $this->alertDays() => 'expiring',
            default => 'valid',
        };

        return ['status' => $status, 'days_remaining' => $days];
    }

    /**
     * Restricts a query to documents in an alert state: "expired", "expiring" or "alerts" (both).
     */
    public function applyStatus(Builder $query, string $status): Builder
    {
        $today = $this->today()->toDateString();
        $limit = $this->today()->addDays($this->alertDays())->toDateString();

        return match ($status) {
            'expired' => $query->where('expiry_date', '<', $today),
            'expiring' => $query->whereBetween('expiry_date', [$today, $limit]),
            'valid' => $query->where('expiry_date', '>', $limit),
            default => $query->where('expiry_date', '<=', $limit),
        };
    }
}

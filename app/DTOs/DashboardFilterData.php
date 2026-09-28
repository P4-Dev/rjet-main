<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class DashboardFilterData
{
    public function __construct(
        public ?string $companyId,
        public ?string $branchId,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public CarbonImmutable $today,
    ) {}

    /**
     * Filters come from the URL and the session, so anything malformed falls back to defaults.
     * Visibility is never derived from here: the service always applies visibleTo($user).
     *
     * @param  array<string, mixed>  $filters
     */
    public static function fromPageFilters(array $filters, User $user, CarbonImmutable $today): self
    {
        $today = $today->startOfDay();
        $start = self::parseDate($filters['period_start'] ?? null, $today) ?? $today->startOfMonth();
        $end = self::parseDate($filters['period_end'] ?? null, $today) ?? $today->endOfMonth()->startOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return new self(
            companyId: self::uuidOrNull($filters['company_id'] ?? null),
            branchId: self::uuidOrNull($filters['branch_id'] ?? null),
            periodStart: $start,
            periodEnd: $end,
            today: $today,
        );
    }

    public function cacheHash(): string
    {
        return md5(implode('|', [
            $this->companyId ?? '',
            $this->branchId ?? '',
            $this->periodStart->toDateString(),
            $this->periodEnd->toDateString(),
            $this->today->toDateString(),
        ]));
    }

    public function isDefaultMonth(): bool
    {
        return $this->periodStart->toDateString() === $this->today->startOfMonth()->toDateString()
            && $this->periodEnd->toDateString() === $this->today->endOfMonth()->toDateString();
    }

    public function isPeriodClosed(): bool
    {
        return $this->periodEnd->toDateString() < $this->today->toDateString();
    }

    private static function parseDate(mixed $value, CarbonImmutable $today): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), $today->getTimezone());

        return $date !== null && $date->format('Y-m-d') === substr($value, 0, 10) ? $date : null;
    }

    private static function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }
}

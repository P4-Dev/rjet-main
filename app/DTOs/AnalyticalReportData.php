<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PaymentRequestStatus;
use App\Enums\ReportDateBasis;
use App\Models\AnalyticalReport;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class AnalyticalReportData
{
    /**
     * @param  list<PaymentRequestStatus>|null  $statuses
     */
    public function __construct(
        public ?string $companyId,
        public ?string $branchId,
        public ReportDateBasis $dateBasis,
        public ?CarbonImmutable $periodStart,
        public ?CarbonImmutable $periodEnd,
        public ?array $statuses = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $basis = $data['date_basis'] ?? ReportDateBasis::DueDate;

        return new self(
            companyId: filled($data['company_id'] ?? null) ? (string) $data['company_id'] : null,
            branchId: filled($data['branch_id'] ?? null) ? (string) $data['branch_id'] : null,
            dateBasis: $basis instanceof ReportDateBasis ? $basis : ReportDateBasis::from((string) $basis),
            periodStart: self::date($data['period_start'] ?? null),
            periodEnd: self::date($data['period_end'] ?? null),
            statuses: self::statuses($data['statuses'] ?? null),
        );
    }

    public static function fromModel(AnalyticalReport $report): self
    {
        return new self(
            companyId: $report->company_id,
            branchId: $report->branch_id,
            dateBasis: $report->date_basis,
            periodStart: self::date($report->period_start),
            periodEnd: self::date($report->period_end),
            statuses: self::statuses($report->statuses),
        );
    }

    /**
     * @return array{company_id: ?string, branch_id: ?string, date_basis: string, period_start: ?string, period_end: ?string, statuses: list<string>|null}
     */
    public function toArray(): array
    {
        return [
            'company_id' => $this->companyId,
            'branch_id' => $this->branchId,
            'date_basis' => $this->dateBasis->value,
            'period_start' => $this->periodStart?->toDateString(),
            'period_end' => $this->periodEnd?->toDateString(),
            'statuses' => $this->statusValues(),
        ];
    }

    /**
     * Unique, sorted values; an empty selection means "all" and is stored as null.
     *
     * @return list<string>|null
     */
    public function statusValues(): ?array
    {
        if ($this->statuses === null) {
            return null;
        }

        $values = array_values(array_unique(array_map(
            fn (PaymentRequestStatus $status): string => $status->value,
            $this->statuses,
        )));
        sort($values);

        return $values === [] ? null : $values;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::parse($value->toDateString(), config('app.timezone'));
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(substr($value, 0, 10), config('app.timezone'))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<PaymentRequestStatus>|null
     */
    private static function statuses(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        $statuses = [];

        foreach ($value as $status) {
            $case = $status instanceof PaymentRequestStatus ? $status : PaymentRequestStatus::tryFrom((string) $status);

            if ($case !== null) {
                $statuses[] = $case;
            }
        }

        return $statuses === [] ? null : $statuses;
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnalyticalReportStatus;
use App\Enums\PaymentRequestStatus;
use App\Enums\ReportDateBasis;
use App\Models\AnalyticalReport;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AnalyticalReport>
 */
final class AnalyticalReportFactory extends Factory
{
    protected $model = AnalyticalReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $today = today(config('app.timezone'));

        return [
            'status' => AnalyticalReportStatus::Queued,
            'date_basis' => ReportDateBasis::DueDate,
            'period_start' => $today->copy()->startOfMonth()->toDateString(),
            'period_end' => $today->copy()->endOfMonth()->toDateString(),
            'statuses' => null,
            'disk' => (string) config('rjet.reports.disk'),
            'created_by' => User::factory()->operador(),
            'updated_by' => fn (array $attributes): mixed => $attributes['created_by'],
        ];
    }

    public function queued(): static
    {
        return $this->state(fn (): array => ['status' => AnalyticalReportStatus::Queued]);
    }

    public function generating(): static
    {
        return $this->state(fn (): array => [
            'status' => AnalyticalReportStatus::Generating,
            'started_at' => now(),
        ]);
    }

    public function generated(): static
    {
        return $this->state(function (): array {
            $now = now();

            return [
                'status' => AnalyticalReportStatus::Generated,
                'path' => sprintf('reports/%s/%s.xlsx', $now->format('Y/m'), Str::uuid()),
                'filename' => sprintf('relatorio-analitico_%s.xlsx', $now->format('YmdHi')),
                'size' => fake()->numberBetween(1024, 204800),
                'rows_count' => fake()->numberBetween(1, 500),
                'started_at' => $now,
                'generated_at' => $now,
            ];
        });
    }

    public function failed(string $reason = 'Falha de teste.'): static
    {
        return $this->state(fn (): array => [
            'status' => AnalyticalReportStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }

    public function stale(): static
    {
        return $this->generating()->state(fn (): array => [
            'updated_at' => now()->subMinutes((int) config('rjet.reports.stale_after_minutes') + 5),
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (): array => ['company_id' => $company->getKey()]);
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (): array => [
            'company_id' => $branch->company_id,
            'branch_id' => $branch->getKey(),
        ]);
    }

    public function basis(ReportDateBasis $basis): static
    {
        return $this->state(fn (): array => ['date_basis' => $basis]);
    }

    /**
     * @param  list<PaymentRequestStatus|string>  $statuses
     */
    public function withStatuses(array $statuses): static
    {
        $values = array_values(array_unique(array_map(
            fn (PaymentRequestStatus|string $status): string => $status instanceof PaymentRequestStatus ? $status->value : $status,
            $statuses,
        )));
        sort($values);

        return $this->state(fn (): array => ['statuses' => $values === [] ? null : $values]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'created_at' => now()->subDays((int) config('rjet.reports.retention_days') + 1),
        ]);
    }
}

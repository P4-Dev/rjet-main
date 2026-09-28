<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DashboardFilterData;
use App\DTOs\DashboardMetrics;
use App\DTOs\DashboardSeries;
use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentSettlementStatus;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\PaymentRequest;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Date columns are compared with half-open `Y-m-d` bounds (`>= start`, `< end + 1 day`) so the
 * same SQL works on PostgreSQL `date` columns and on SQLite, where Eloquent stores `Y-m-d 00:00:00`.
 */
final class DashboardMetricsService
{
    public const VERSION_KEY = 'dashboard:version';

    private const OTHERS_KEY = '__others__';

    public function stats(DashboardFilterData $filters, User $user): DashboardMetrics
    {
        return DashboardMetrics::fromArray(
            $this->remember('stats', $filters, $user, fn (): array => $this->computeStats($filters, $user)->toArray()),
        );
    }

    public function byBranch(DashboardFilterData $filters, User $user): DashboardSeries
    {
        return DashboardSeries::fromArray(
            $this->remember('by_branch', $filters, $user, fn (): array => $this->computeSeries(
                'branch_id',
                $filters,
                $user,
                fn (array $ids): array => $this->branchLabels($ids),
            )->toArray()),
        );
    }

    public function byCostCenter(DashboardFilterData $filters, User $user): DashboardSeries
    {
        return DashboardSeries::fromArray(
            $this->remember('by_cost_center', $filters, $user, fn (): array => $this->computeSeries(
                'cost_center_id',
                $filters,
                $user,
                fn (array $ids): array => $this->costCenterLabels($ids, $filters->branchId === null),
            )->toArray()),
        );
    }

    /**
     * Bumps the global version after commit; stale keys simply expire by TTL.
     */
    public function flush(): void
    {
        DB::afterCommit(function (): void {
            Cache::add(self::VERSION_KEY, 1);
            Cache::increment(self::VERSION_KEY);
        });
    }

    public function cacheKey(string $block, DashboardFilterData $filters, User $user): string
    {
        return sprintf(
            'dashboard:v%d:%s:%s:%s:%s',
            (int) Cache::get(self::VERSION_KEY, 1),
            $this->scopeKey($user),
            $block,
            app()->getLocale(),
            $filters->cacheHash(),
        );
    }

    /**
     * @param  Closure(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function remember(string $block, DashboardFilterData $filters, User $user, Closure $callback): array
    {
        $ttl = (int) config('rjet.dashboard.cache_ttl_seconds');

        if ($ttl <= 0) {
            return $callback();
        }

        return Cache::remember($this->cacheKey($block, $filters, $user), $ttl, $callback);
    }

    private function scopeKey(User $user): string
    {
        if ($user->role->seesAllBranches()) {
            return 'all';
        }

        $branchIds = $user->branches()->pluck('branches.id')->map(fn (mixed $id): string => (string) $id)->sort()->values()->all();

        return 'branches:'.md5(implode(',', $branchIds));
    }

    private function computeStats(DashboardFilterData $filters, User $user): DashboardMetrics
    {
        $today = $filters->today->toDateString();

        $open = $this->openBaseQuery($filters, $user)
            ->toBase()
            ->selectRaw(
                'COALESCE(SUM(payment_requests.net_amount), 0) AS open_amount, '
                .'COUNT(*) AS open_count, '
                .'COALESCE(SUM(CASE WHEN payment_requests.due_date >= ? THEN payment_requests.net_amount ELSE 0 END), 0) AS upcoming_amount, '
                .'COALESCE(SUM(CASE WHEN payment_requests.due_date >= ? THEN 1 ELSE 0 END), 0) AS upcoming_count, '
                .'COALESCE(SUM(CASE WHEN payment_requests.due_date < ? THEN payment_requests.net_amount ELSE 0 END), 0) AS overdue_amount, '
                .'COALESCE(SUM(CASE WHEN payment_requests.due_date < ? THEN 1 ELSE 0 END), 0) AS overdue_count, '
                .'COALESCE(SUM(CASE WHEN payment_requests.status = ? THEN 1 ELSE 0 END), 0) AS requested_count, '
                .'COALESCE(SUM(CASE WHEN payment_requests.status = ? THEN 1 ELSE 0 END), 0) AS launched_count',
                [
                    $today,
                    $today,
                    $today,
                    $today,
                    PaymentRequestStatus::Requested->value,
                    PaymentRequestStatus::Launched->value,
                ],
            )
            ->first();

        $paid = $this->paidBaseQuery($filters, $user)
            ->toBase()
            ->selectRaw(
                'COALESCE(SUM(payment_settlement_items.amount), 0) AS paid_amount, '
                .'COUNT(payment_settlement_items.id) AS paid_count',
            )
            ->first();

        return new DashboardMetrics(
            paidAmount: $this->money($paid->paid_amount ?? null),
            paidCount: (int) ($paid->paid_count ?? 0),
            openAmount: $this->money($open->open_amount ?? null),
            openCount: (int) ($open->open_count ?? 0),
            requestedCount: (int) ($open->requested_count ?? 0),
            launchedCount: (int) ($open->launched_count ?? 0),
            upcomingAmount: $this->money($open->upcoming_amount ?? null),
            upcomingCount: (int) ($open->upcoming_count ?? 0),
            overdueAmount: $this->money($open->overdue_amount ?? null),
            overdueCount: (int) ($open->overdue_count ?? 0),
        );
    }

    /**
     * @param  'branch_id'|'cost_center_id'  $dimension
     * @param  Closure(list<string>): array<string, string>  $labelResolver
     */
    private function computeSeries(string $dimension, DashboardFilterData $filters, User $user, Closure $labelResolver): DashboardSeries
    {
        $column = 'payment_requests.'.$dimension;
        $today = $filters->today->toDateString();

        /** @var array<string, array{paid: string, upcoming: string, overdue: string}> $rows */
        $rows = [];
        $blank = ['paid' => '0.00', 'upcoming' => '0.00', 'overdue' => '0.00'];

        $this->openBaseQuery($filters, $user)
            ->toBase()
            ->selectRaw(
                "{$column} AS dimension_id, "
                .'COALESCE(SUM(CASE WHEN payment_requests.due_date >= ? THEN payment_requests.net_amount ELSE 0 END), 0) AS upcoming_amount, '
                .'COALESCE(SUM(CASE WHEN payment_requests.due_date < ? THEN payment_requests.net_amount ELSE 0 END), 0) AS overdue_amount',
                [$today, $today],
            )
            ->groupBy($column)
            ->get()
            ->each(function (object $row) use (&$rows, $blank): void {
                $key = (string) $row->dimension_id;
                $rows[$key] = [
                    ...($rows[$key] ?? $blank),
                    'upcoming' => $this->money($row->upcoming_amount),
                    'overdue' => $this->money($row->overdue_amount),
                ];
            });

        $this->paidBaseQuery($filters, $user)
            ->toBase()
            ->selectRaw("{$column} AS dimension_id, COALESCE(SUM(payment_settlement_items.amount), 0) AS paid_amount")
            ->groupBy($column)
            ->get()
            ->each(function (object $row) use (&$rows, $blank): void {
                $key = (string) $row->dimension_id;
                $rows[$key] = [...($rows[$key] ?? $blank), 'paid' => $this->money($row->paid_amount)];
            });

        if ($rows === []) {
            return new DashboardSeries([], [], [], []);
        }

        $labels = $labelResolver(array_map('strval', array_keys($rows)));

        $entries = [];
        foreach ($rows as $key => $values) {
            $entries[] = [
                'label' => $labels[(string) $key] ?? '—',
                'total' => bcadd(bcadd($values['paid'], $values['upcoming'], 2), $values['overdue'], 2),
                ...$values,
            ];
        }

        usort($entries, fn (array $a, array $b): int => bccomp($b['total'], $a['total'], 2) ?: strcmp($a['label'], $b['label']));

        $entries = $this->collapseOthers($entries);

        return new DashboardSeries(
            labels: array_column($entries, 'label'),
            paid: array_column($entries, 'paid'),
            upcoming: array_column($entries, 'upcoming'),
            overdue: array_column($entries, 'overdue'),
        );
    }

    /**
     * Keeps at most `chart_max_categories` bars, the last one being "Others" when needed.
     *
     * @param  list<array{label: string, total: string, paid: string, upcoming: string, overdue: string}>  $entries
     * @return list<array{label: string, total: string, paid: string, upcoming: string, overdue: string}>
     */
    private function collapseOthers(array $entries): array
    {
        $max = max(2, (int) config('rjet.dashboard.chart_max_categories'));

        if (count($entries) <= $max) {
            return $entries;
        }

        $named = array_slice($entries, 0, $max - 1);
        $others = ['label' => __('dashboard.charts.others'), 'total' => '0.00', 'paid' => '0.00', 'upcoming' => '0.00', 'overdue' => '0.00'];

        foreach (array_slice($entries, $max - 1) as $entry) {
            foreach (['total', 'paid', 'upcoming', 'overdue'] as $measure) {
                $others[$measure] = bcadd($others[$measure], $entry[$measure], 2);
            }
        }

        return [...$named, $others];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function branchLabels(array $ids): array
    {
        return Branch::withTrashed()
            ->whereKey($ids)
            ->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $name, mixed $id): array => [(string) $id => (string) $name])
            ->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function costCenterLabels(array $ids, bool $withBranch): array
    {
        return CostCenter::withTrashed()
            ->whereKey($ids)
            ->with(['branch' => fn ($query) => $query->withTrashed()])
            ->get()
            ->mapWithKeys(function (CostCenter $costCenter) use ($withBranch): array {
                $label = sprintf('%s — %s', $costCenter->code, $costCenter->name);

                if ($withBranch && $costCenter->branch !== null) {
                    $label .= sprintf(' (%s)', $costCenter->branch->name);
                }

                return [(string) $costCenter->getKey() => $label];
            })
            ->all();
    }

    /**
     * Open position at "today": requested + launched with due date up to the period end, no lower bound.
     *
     * @return Builder<PaymentRequest>
     */
    private function openBaseQuery(DashboardFilterData $filters, User $user): Builder
    {
        return $this->scopedQuery($filters, $user)
            ->open()
            ->where('payment_requests.due_date', '<', $filters->periodEnd->addDay()->toDateString());
    }

    /**
     * Paid = active item of a settled, non-deleted settlement whose settlement date is in the period.
     *
     * @return Builder<PaymentRequest>
     */
    private function paidBaseQuery(DashboardFilterData $filters, User $user): Builder
    {
        return $this->scopedQuery($filters, $user)
            ->join('payment_settlement_items', function (JoinClause $join): void {
                $join->on('payment_settlement_items.payment_request_id', '=', 'payment_requests.id')
                    ->whereNull('payment_settlement_items.released_at');
            })
            ->join('payment_settlements', 'payment_settlements.id', '=', 'payment_settlement_items.payment_settlement_id')
            ->where('payment_settlements.status', PaymentSettlementStatus::Settled->value)
            ->whereNull('payment_settlements.deleted_at')
            ->where('payment_settlements.settlement_date', '>=', $filters->periodStart->toDateString())
            ->where('payment_settlements.settlement_date', '<', $filters->periodEnd->addDay()->toDateString());
    }

    /**
     * @return Builder<PaymentRequest>
     */
    private function scopedQuery(DashboardFilterData $filters, User $user): Builder
    {
        return PaymentRequest::query()
            ->visibleTo($user)
            ->when($filters->companyId, fn (Builder $query, string $companyId): Builder => $query->forCompany($companyId))
            ->when($filters->branchId, fn (Builder $query, string $branchId): Builder => $query->forBranch($branchId));
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1) {
            return bcadd($value, '0', 2);
        }

        return number_format(round((float) $value, 2), 2, '.', '');
    }
}

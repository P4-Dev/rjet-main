<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\AnalyticalReportData;
use App\Enums\AnalyticalReportStatus;
use App\Enums\PaymentDueSituation;
use App\Enums\PaymentSettlementStatus;
use App\Enums\ReportDateBasis;
use App\Events\Report\AnalyticalReportDownloaded;
use App\Events\Report\AnalyticalReportGenerated;
use App\Events\Report\AnalyticalReportGenerationFailed;
use App\Events\Report\AnalyticalReportRequested;
use App\Exceptions\AnalyticalReportException;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Integrations\Spreadsheet\SpreadsheetCell;
use App\Integrations\Spreadsheet\SpreadsheetWriter;
use App\Models\AnalyticalReport;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestBankDetails;
use App\Models\PaymentSettlement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class AnalyticalReportService
{
    public const TIMEZONE = 'America/Sao_Paulo';

    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly SpreadsheetWriter $writer,
    ) {}

    public function countMatching(AnalyticalReportData $data, User $user): int
    {
        return $this->rowsQuery($data, $user)->count();
    }

    /**
     * @throws AnalyticalReportException
     */
    public function request(AnalyticalReportData $data, User $user): AnalyticalReport
    {
        if (! Gate::forUser($user)->allows('create', AnalyticalReport::class)) {
            throw AnalyticalReportException::unauthorized();
        }

        if ($data->periodStart === null || $data->periodEnd === null || $data->periodStart->greaterThan($data->periodEnd)) {
            throw AnalyticalReportException::invalidPeriod();
        }

        $maxInProgress = (int) config('rjet.reports.max_in_progress_per_user');

        if (AnalyticalReport::query()->createdBy($user)->inProgress()->count() >= $maxInProgress) {
            throw AnalyticalReportException::tooManyInProgress($maxInProgress);
        }

        if ($data->branchId !== null && $data->companyId !== null
            && ! Branch::withTrashed()->whereKey($data->branchId)->where('company_id', $data->companyId)->exists()) {
            throw AnalyticalReportException::noMatchingRequests();
        }

        $count = $this->countMatching($data, $user);
        $maxRows = (int) config('rjet.reports.max_rows');

        if ($count === 0) {
            throw AnalyticalReportException::noMatchingRequests();
        }

        if ($count > $maxRows) {
            throw AnalyticalReportException::tooManyRows($count, $maxRows);
        }

        return DB::transaction(function () use ($data, $user): AnalyticalReport {
            /** @var AnalyticalReport $report */
            $report = AnalyticalReport::query()->create([
                'status' => AnalyticalReportStatus::Queued,
                'company_id' => $data->companyId,
                'branch_id' => $data->branchId,
                'date_basis' => $data->dateBasis,
                'period_start' => $data->periodStart->toDateString(),
                'period_end' => $data->periodEnd->toDateString(),
                'statuses' => $data->statusValues(),
                'disk' => (string) config('rjet.reports.disk'),
                'created_by' => $user->getKey(),
                'updated_by' => $user->getKey(),
            ]);

            Event::dispatch(new AnalyticalReportRequested($report));

            return $report;
        });
    }

    /**
     * Called by GenerateAnalyticalReportJob. Deterministic failures mark the report failed without
     * throwing; infrastructure failures throw so the job retries. Concurrent runs write the same path
     * and only the conditional publication dispatches the generated event.
     *
     * @throws AnalyticalReportException
     */
    public function generate(AnalyticalReport $report): void
    {
        $report = $report->fresh();

        if ($report === null || $report->trashed() || ! $report->status->isInProgress()) {
            return;
        }

        $started = AnalyticalReport::query()
            ->whereKey($report->getKey())
            ->whereIn('status', AnalyticalReportStatus::inProgressValues())
            ->update([
                'status' => AnalyticalReportStatus::Generating->value,
                'started_at' => $report->started_at ?? now(),
            ]);

        if ($started === 0) {
            return;
        }

        $report->refresh();
        $creator = $report->creator()->first();

        if ($creator === null || ! $creator->is_active || ! Gate::forUser($creator)->allows('create', AnalyticalReport::class)) {
            $this->markFailed($report, AnalyticalReportException::requesterUnavailable()->getUserMessage());

            return;
        }

        $data = AnalyticalReportData::fromModel($report);

        /** @var Collection<int, string> $ids */
        $ids = $this->rowsQuery($data, $creator)
            ->orderBy('payment_requests.due_date')
            ->orderBy('payment_requests.created_at')
            ->orderBy('payment_requests.id')
            ->pluck('payment_requests.id')
            ->map(fn (mixed $id): string => (string) $id);

        $maxRows = (int) config('rjet.reports.max_rows');

        if ($ids->count() > $maxRows) {
            $this->markFailed($report, AnalyticalReportException::tooManyRows($ids->count(), $maxRows)->getUserMessage());

            return;
        }

        $attachmentColumns = $this->attachmentColumns($data, $creator);
        $tempFile = (string) tempnam(sys_get_temp_dir(), 'rjet-report-');

        try {
            $attachmentsCount = $this->writeWorkbook($tempFile, $ids, $attachmentColumns);
            $size = (int) filesize($tempFile);

            $createdAt = CarbonImmutable::instance($report->created_at)->setTimezone(self::TIMEZONE);
            $directory = sprintf('%s/%s', config('rjet.reports.directory'), $createdAt->format('Y/m'));
            $basename = $report->getKey().'.xlsx';
            $path = $this->store((string) $report->disk, $directory, $tempFile, $basename);

            $published = DB::transaction(fn (): int => AnalyticalReport::query()
                ->whereKey($report->getKey())
                ->where('status', AnalyticalReportStatus::Generating->value)
                ->update([
                    'status' => AnalyticalReportStatus::Generated->value,
                    'path' => $path,
                    'filename' => $this->filename($report, $createdAt),
                    'size' => $size,
                    'rows_count' => $ids->count(),
                    'attachments_count' => $attachmentsCount,
                    'generated_at' => now(),
                    'failure_reason' => null,
                ]));
        } finally {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }

        if ($published === 0) {
            return;
        }

        Event::dispatch(new AnalyticalReportGenerated($report->refresh()));
    }

    public function markFailed(AnalyticalReport $report, string $userReason): void
    {
        $updated = AnalyticalReport::query()
            ->whereKey($report->getKey())
            ->whereIn('status', AnalyticalReportStatus::inProgressValues())
            ->update([
                'status' => AnalyticalReportStatus::Failed->value,
                'failure_reason' => $userReason,
            ]);

        if ($updated === 0) {
            return;
        }

        $report->refresh();

        Event::dispatch(new AnalyticalReportGenerationFailed($report));
    }

    /**
     * @throws AnalyticalReportException
     */
    public function retry(AnalyticalReport $report, User $user): AnalyticalReport
    {
        if (! $report->isRetryable()) {
            throw AnalyticalReportException::notRetryable();
        }

        if (! Gate::forUser($user)->allows('retry', $report)) {
            throw AnalyticalReportException::unauthorized();
        }

        return DB::transaction(function () use ($report, $user): AnalyticalReport {
            $requeued = AnalyticalReport::query()
                ->whereKey($report->getKey())
                ->where('status', $report->status->value)
                ->where('updated_at', $report->getRawOriginal('updated_at'))
                ->update([
                    'status' => AnalyticalReportStatus::Queued->value,
                    'failure_reason' => null,
                    'updated_by' => $user->getKey(),
                ]);

            if ($requeued === 0) {
                throw AnalyticalReportException::notRetryable();
            }

            $report->refresh();

            Event::dispatch(new AnalyticalReportRequested($report));

            return $report;
        });
    }

    /**
     * @throws AnalyticalReportException
     */
    public function download(AnalyticalReport $report, User $user, ?string $ipAddress = null): StreamedResponse
    {
        if (! $report->isDownloadable()) {
            throw AnalyticalReportException::notDownloadable();
        }

        if (! Gate::forUser($user)->allows('download', $report)) {
            throw AnalyticalReportException::unauthorized();
        }

        $storage = Storage::disk((string) $report->disk);

        if (! $storage->exists((string) $report->path)) {
            Log::error('Analytical report file is missing.', ['analytical_report_id' => $report->getKey()]);

            throw AnalyticalReportException::fileMissing();
        }

        Event::dispatch(new AnalyticalReportDownloaded($report, $user, $ipAddress));

        return $storage->download((string) $report->path, (string) $report->filename);
    }

    /**
     * No ordering, eager loading or join: callers add what they need.
     *
     * @return Builder<PaymentRequest>
     */
    public function rowsQuery(AnalyticalReportData $data, User $user): Builder
    {
        $query = PaymentRequest::query()
            ->visibleTo($user)
            ->when($data->companyId, fn (Builder $q, string $companyId): Builder => $q->forCompany($companyId))
            ->when($data->branchId, fn (Builder $q, string $branchId): Builder => $q->forBranch($branchId))
            ->when($data->statusValues(), fn (Builder $q, array $statuses): Builder => $q->whereIn($q->qualifyColumn('status'), $statuses));

        $start = $data->periodStart ?? CarbonImmutable::today(config('app.timezone'));
        $end = $data->periodEnd ?? $start;

        return match ($data->dateBasis) {
            ReportDateBasis::DueDate => $query
                ->whereDate($query->qualifyColumn('due_date'), '>=', $start->toDateString())
                ->whereDate($query->qualifyColumn('due_date'), '<=', $end->toDateString()),
            ReportDateBasis::SettlementDate => $query->whereHas(
                'activeSettlementItem.settlement',
                fn (Builder $settlement): Builder => $settlement
                    ->whereNull($settlement->qualifyColumn('deleted_at'))
                    ->where($settlement->qualifyColumn('status'), PaymentSettlementStatus::Settled->value)
                    ->whereDate($settlement->qualifyColumn('settlement_date'), '>=', $start->toDateString())
                    ->whereDate($settlement->qualifyColumn('settlement_date'), '<=', $end->toDateString()),
            ),
            ReportDateBasis::RequestDate => $query
                ->where($query->qualifyColumn('created_at'), '>=', $this->dayStartInStorageTimezone($start))
                ->where($query->qualifyColumn('created_at'), '<', $this->dayStartInStorageTimezone($end->addDay())),
        };
    }

    /**
     * Business days are São Paulo days; the bound is converted to the timezone timestamps are written in.
     */
    private function dayStartInStorageTimezone(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString(), self::TIMEZONE)
            ->startOfDay()
            ->setTimezone((string) config('app.timezone'));
    }

    /**
     * Largest attachment count per row, capped; aggregated in SQL to avoid binding every id.
     */
    private function attachmentColumns(AnalyticalReportData $data, User $creator): int
    {
        $perRequest = Attachment::query()
            ->where('attachable_type', (new PaymentRequest)->getMorphClass())
            ->whereIn('attachable_id', $this->rowsQuery($data, $creator)->select('payment_requests.id'))
            ->groupBy('attachable_id')
            ->selectRaw('attachable_id, COUNT(*) AS cnt')
            ->toBase();

        $max = (int) DB::query()->fromSub($perRequest, 'counts')->max('cnt');

        return min($max, (int) config('rjet.reports.max_attachment_columns'));
    }

    /**
     * @param  Collection<int, string>  $ids
     * @return int attachments written on the second sheet
     */
    private function writeWorkbook(string $absolutePath, Collection $ids, int $attachmentColumns): int
    {
        $today = CarbonImmutable::today(self::TIMEZONE);

        $this->writer->open($absolutePath);

        try {
            $this->writer->addSheet(__('analytical_reports.sheet.requests'), $this->requestHeaders($attachmentColumns));

            foreach ($ids->chunk(self::CHUNK_SIZE) as $chunk) {
                $requests = PaymentRequest::query()
                    ->whereKey($chunk->values()->all())
                    ->with([
                        'branch' => fn ($q) => $q->withTrashed(),
                        'branch.company' => fn ($q) => $q->withTrashed(),
                        'supplier' => fn ($q) => $q->withTrashed(),
                        'costCenter' => fn ($q) => $q->withTrashed(),
                        'costCenter.branch' => fn ($q) => $q->withTrashed(),
                        'appropriation' => fn ($q) => $q->withTrashed(),
                        'bankDetails.bank' => fn ($q) => $q->withTrashed(),
                        'creator',
                        'attachments',
                        'activeSettlementItem.settlement.branchBankAccount',
                    ])
                    ->get()
                    ->keyBy(fn (PaymentRequest $request): string => (string) $request->getKey());

                foreach ($chunk as $id) {
                    $request = $requests->get($id);

                    if ($request !== null) {
                        $this->writer->addRow($this->requestRow($request, $today, $attachmentColumns));
                    }
                }
            }

            $this->writer->addSheet(__('analytical_reports.sheet.attachments'), $this->attachmentHeaders());
            $attachmentsCount = 0;

            foreach ($ids->chunk(self::CHUNK_SIZE) as $chunk) {
                $requests = PaymentRequest::query()
                    ->whereKey($chunk->values()->all())
                    ->with([
                        'branch' => fn ($q) => $q->withTrashed(),
                        'supplier' => fn ($q) => $q->withTrashed(),
                        'attachments',
                    ])
                    ->get()
                    ->keyBy(fn (PaymentRequest $request): string => (string) $request->getKey());

                foreach ($chunk as $id) {
                    $request = $requests->get($id);

                    foreach ($request?->attachments ?? [] as $attachment) {
                        $this->writer->addRow($this->attachmentRow($request, $attachment));
                        $attachmentsCount++;
                    }
                }
            }
        } finally {
            $this->writer->close();
        }

        return $attachmentsCount;
    }

    /**
     * @return list<string>
     */
    private function requestHeaders(int $attachmentColumns): array
    {
        $keys = [
            'request_id', 'open_request', 'company', 'branch', 'branch_document', 'status', 'situation',
            'request_date', 'due_date', 'requester', 'person_type', 'supplier_document', 'supplier',
            'supplier_legal_name', 'cost_center', 'appropriation', 'payment_method', 'deposit_type',
            'gross_amount', 'discount_amount', 'net_amount', 'digitable_line', 'pix_key', 'beneficiary_bank',
            'agency', 'account', 'holder_name', 'holder_document', 'settlement_status', 'settlement_date',
            'settled_amount', 'payer_account', 'settled_at', 'notes', 'attachments_count',
        ];

        $headers = array_map(fn (string $key): string => __('analytical_reports.columns.'.$key), $keys);

        for ($number = 1; $number <= $attachmentColumns; $number++) {
            $headers[] = __('analytical_reports.columns.attachment', ['number' => $number]);
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    private function attachmentHeaders(): array
    {
        return array_map(
            fn (string $key): string => __('analytical_reports.columns.'.$key),
            ['request_id', 'branch', 'supplier', 'due_date', 'attachment_type', 'name', 'mime_type', 'size_kb', 'uploaded_at', 'open'],
        );
    }

    /**
     * @return list<SpreadsheetCell>
     */
    private function requestRow(PaymentRequest $request, CarbonImmutable $today, int $attachmentColumns): array
    {
        $details = $request->bankDetails;
        $item = $request->activeSettlementItem;
        $settlement = $item?->settlement;
        $attachments = $request->attachments;

        $row = [
            SpreadsheetCell::text((string) $request->getKey()),
            SpreadsheetCell::link(
                PaymentRequestResource::getUrl('view', ['record' => $request]),
                __('analytical_reports.columns.open_request'),
            ),
            SpreadsheetCell::text($request->branch?->company?->name),
            SpreadsheetCell::text($request->branch?->name),
            SpreadsheetCell::text($request->branch?->document),
            SpreadsheetCell::text($request->status->getLabel()),
            SpreadsheetCell::text(PaymentDueSituation::for($request, $today)->getLabel()),
            SpreadsheetCell::dateTime($this->inBusinessTimezone($request->created_at)),
            SpreadsheetCell::date($request->due_date),
            SpreadsheetCell::text($request->creator?->name),
            SpreadsheetCell::text($request->supplier?->person_type?->getLabel()),
            SpreadsheetCell::text($request->supplier?->document),
            SpreadsheetCell::text($request->supplier?->name),
            SpreadsheetCell::text($request->supplier?->legal_name),
            SpreadsheetCell::text($this->codeAndName($request->costCenter?->code, $request->costCenter?->name)),
            SpreadsheetCell::text($this->codeAndName($request->appropriation?->code, $request->appropriation?->name)),
            SpreadsheetCell::text($request->payment_method?->getLabel()),
            SpreadsheetCell::text($details?->deposit_type?->getLabel()),
            SpreadsheetCell::money((string) $request->gross_amount),
            SpreadsheetCell::money((string) $request->discount_amount),
            SpreadsheetCell::money((string) $request->net_amount),
            SpreadsheetCell::text($this->boletoCode($details)),
            SpreadsheetCell::text($this->pixKey($details)),
            SpreadsheetCell::text($this->codeAndName($details?->bank?->code, $details?->bank?->name)),
            SpreadsheetCell::text($this->joinDigit($details?->agency, $details?->agency_digit)),
            SpreadsheetCell::text($this->beneficiaryAccount($details)),
            SpreadsheetCell::text($details?->holder_name),
            SpreadsheetCell::text($details?->holder_document),
            SpreadsheetCell::text($settlement?->status?->getLabel()),
            SpreadsheetCell::date($settlement?->settlement_date),
            SpreadsheetCell::money($item !== null ? (string) $item->amount : null),
            SpreadsheetCell::text($this->payerAccount($settlement)),
            SpreadsheetCell::dateTime($this->inBusinessTimezone($settlement?->settled_at)),
            SpreadsheetCell::text($request->notes),
            SpreadsheetCell::number($attachments->count()),
        ];

        for ($index = 0; $index < $attachmentColumns; $index++) {
            $attachment = $attachments->get($index);
            $row[] = $attachment !== null
                ? SpreadsheetCell::link($this->attachmentUrl($attachment), $attachment->displayName())
                : SpreadsheetCell::text(null);
        }

        return $row;
    }

    /**
     * @return list<SpreadsheetCell>
     */
    private function attachmentRow(PaymentRequest $request, Attachment $attachment): array
    {
        return [
            SpreadsheetCell::text((string) $request->getKey()),
            SpreadsheetCell::text($request->branch?->name),
            SpreadsheetCell::text($request->supplier?->name),
            SpreadsheetCell::date($request->due_date),
            SpreadsheetCell::text($attachment->type?->getLabel()),
            SpreadsheetCell::text($attachment->displayName()),
            SpreadsheetCell::text($attachment->mime_type),
            SpreadsheetCell::number($attachment->size !== null ? round($attachment->size / 1024, 1) : null),
            SpreadsheetCell::dateTime($this->inBusinessTimezone($attachment->created_at)),
            SpreadsheetCell::link($this->attachmentUrl($attachment), $attachment->displayName()),
        ];
    }

    public function attachmentUrl(Attachment $attachment): string
    {
        $relative = URL::signedRoute(
            'filament.admin.report-attachments.open',
            ['attachment' => $attachment],
            absolute: false,
        );

        return rtrim((string) config('app.url'), '/').$relative;
    }

    private function inBusinessTimezone(?CarbonInterface $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::instance($value)->setTimezone(self::TIMEZONE);
    }

    private function codeAndName(?string $code, ?string $name): ?string
    {
        if (blank($code) && blank($name)) {
            return null;
        }

        return trim(sprintf('%s — %s', $code, $name), ' —');
    }

    private function joinDigit(?string $number, ?string $digit): ?string
    {
        if (blank($number)) {
            return null;
        }

        return filled($digit) ? "{$number}-{$digit}" : $number;
    }

    private function boletoCode(?PaymentRequestBankDetails $details): ?string
    {
        return filled($details?->digitable_line) ? $details->digitable_line : $details?->barcode;
    }

    private function pixKey(?PaymentRequestBankDetails $details): ?string
    {
        if ($details === null) {
            return null;
        }

        if (filled($details->pix_key)) {
            return $details->pix_key_type !== null
                ? sprintf('%s: %s', $details->pix_key_type->getLabel(), $details->pix_key)
                : $details->pix_key;
        }

        return filled($details->pix_qr_code) ? __('analytical_reports.pix_qr_code') : null;
    }

    private function beneficiaryAccount(?PaymentRequestBankDetails $details): ?string
    {
        $account = $this->joinDigit($details?->account_number, $details?->account_digit);

        if ($account === null) {
            return null;
        }

        return $details?->account_type !== null
            ? sprintf('%s (%s)', $account, $details->account_type->getLabel())
            : $account;
    }

    private function payerAccount(?PaymentSettlement $settlement): ?string
    {
        $account = $settlement?->branchBankAccount;

        if ($account === null) {
            return null;
        }

        return __('analytical_reports.payer_account_format', [
            'bank' => $account->bank_code,
            'agency' => $account->agency,
            'account' => $this->joinDigit($account->account_number, $account->account_digit),
        ]);
    }

    private function filename(AnalyticalReport $report, CarbonImmutable $createdAt): string
    {
        return sprintf(
            '%s_%s-%s_%s.xlsx',
            __('analytical_reports.filename_prefix'),
            $report->period_start->format('Ymd'),
            $report->period_end->format('Ymd'),
            $createdAt->format('YmdHi'),
        );
    }

    /**
     * @throws AnalyticalReportException
     */
    private function store(string $disk, string $directory, string $tempFile, string $basename): string
    {
        try {
            $path = Storage::disk($disk)->putFileAs($directory, $tempFile, $basename);
        } catch (Throwable $exception) {
            throw AnalyticalReportException::storageWriteFailed($exception);
        }

        if (! is_string($path) || $path === '') {
            throw AnalyticalReportException::storageWriteFailed();
        }

        return $path;
    }
}

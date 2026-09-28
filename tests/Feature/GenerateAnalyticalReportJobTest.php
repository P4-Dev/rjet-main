<?php

declare(strict_types=1);

use App\Enums\AnalyticalReportStatus;
use App\Enums\ReportDateBasis;
use App\Events\Report\AnalyticalReportGenerated;
use App\Events\Report\AnalyticalReportGenerationFailed;
use App\Exceptions\AnalyticalReportException;
use App\Integrations\Spreadsheet\OpenSpoutXlsxSpreadsheetWriter;
use App\Integrations\Spreadsheet\SpreadsheetReader;
use App\Integrations\Spreadsheet\SpreadsheetWriter;
use App\Jobs\Report\GenerateAnalyticalReportJob;
use App\Models\AnalyticalReport;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\CostCenter;
use App\Models\PaymentRequest;
use App\Models\PaymentSettlement;
use App\Models\PaymentSettlementItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AnalyticalReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;

/**
 * @return array<string, list<array<string, mixed>>> sheet name => rows keyed by header
 */
function readReportWorkbook(AnalyticalReport $report): array
{
    $reader = new Reader;
    $reader->open(Storage::disk($report->disk)->path((string) $report->path));
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $headers = null;
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $values = array_map(fn ($cell): mixed => $cell->getValue(), $row->getCells());

            if ($headers === null) {
                $headers = $values;

                continue;
            }

            $rows[] = array_combine($headers, array_map(fn (int $index): mixed => $values[$index] ?? null, array_keys($headers)));
        }

        $sheets[$sheet->getName()] = ['headers' => $headers ?? [], 'rows' => $rows];
    }

    $reader->close();

    return $sheets;
}

function reportColumn(string $key): string
{
    return __('analytical_reports.columns.'.$key);
}

function attachToRequest(PaymentRequest $request, int $count): void
{
    foreach (range(0, $count - 1) as $index) {
        Attachment::factory()->create([
            'attachable_id' => $request->getKey(),
            'sort_order' => $index,
            'original_name' => sprintf('anexo-%02d.pdf', $index + 1),
        ]);
    }
}

function runReportJob(AnalyticalReport $report): void
{
    Bus::dispatch(new GenerateAnalyticalReportJob($report));
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.reports.disk' => 'local']);
    Notification::fake();
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-15 10:00', 'America/Sao_Paulo'));

    $this->operador = User::factory()->operador()->create();
    $this->branch = Branch::factory()->create();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('generates the workbook, stores it and records the counters', function (): void {
    $request = PaymentRequest::factory()->forBranch($this->branch)->boleto()->requested()->create([
        'due_date' => '2026-09-20',
        'gross_amount' => '1300.00',
        'discount_amount' => '65.44',
        'net_amount' => '1234.56',
    ]);
    attachToRequest($request, 1);
    $report = AnalyticalReport::factory()->create(['created_by' => $this->operador->getKey()]);

    runReportJob($report);
    $report->refresh();

    expect($report->status)->toBe(AnalyticalReportStatus::Generated)
        ->and($report->path)->toBe("reports/2026/09/{$report->getKey()}.xlsx")
        ->and($report->filename)->toBe('relatorio-analitico_20260901-20260930_202609151000.xlsx')
        ->and($report->rows_count)->toBe(1)
        ->and($report->attachments_count)->toBe(1)
        ->and($report->size)->toBe(Storage::disk('local')->size($report->path))
        ->and($report->generated_at)->not->toBeNull();

    $firstSheet = iterator_to_array(app(SpreadsheetReader::class)->rows(Storage::disk('local')->path($report->path)));
    $workbook = readReportWorkbook($report);
    $requestsSheet = $workbook[__('analytical_reports.sheet.requests')];

    expect(array_keys($workbook))->toBe([__('analytical_reports.sheet.requests'), __('analytical_reports.sheet.attachments')])
        ->and($requestsSheet['headers'][0])->toBe(reportColumn('request_id'))
        ->and($requestsSheet['headers'][8])->toBe(reportColumn('due_date'))
        ->and(end($requestsSheet['headers']))->toBe(__('analytical_reports.columns.attachment', ['number' => 1]))
        ->and($workbook[__('analytical_reports.sheet.attachments')]['headers'])->toHaveCount(10)
        ->and($firstSheet)->toHaveCount(1);

    $row = $firstSheet[0]['values'];
    expect($row[reportColumn('request_id')])->toBe($request->getKey())
        ->and($row[reportColumn('due_date')])->toBe('2026-09-20')
        ->and($row[reportColumn('net_amount')])->toBe(1234.56)
        ->and($row[reportColumn('discount_amount')])->toBe(65.44)
        ->and($row[reportColumn('situation')])->toBe(__('enums.payment_due_situation.upcoming'))
        ->and($row[reportColumn('status')])->toBe($request->status->getLabel())
        ->and($row[reportColumn('open_request')])->toStartWith('=HYPERLINK("http')
        ->and($row[reportColumn('attachments_count')])->toBe(1);
});

it('sizes attachment columns to the largest row with a cap and lists every attachment on the second sheet', function (): void {
    $crowded = PaymentRequest::factory()->forBranch($this->branch)->create(['due_date' => '2026-09-10']);
    attachToRequest($crowded, 11);
    attachToRequest(PaymentRequest::factory()->forBranch($this->branch)->create(['due_date' => '2026-09-11']), 2);
    $otherBranch = Branch::factory()->create();
    attachToRequest(PaymentRequest::factory()->forBranch($otherBranch)->create(['due_date' => '2026-09-12']), 3);

    $capped = AnalyticalReport::factory()->forBranch($this->branch)->create(['created_by' => $this->operador->getKey()]);
    $small = AnalyticalReport::factory()->forBranch($otherBranch)->create(['created_by' => $this->operador->getKey()]);
    runReportJob($capped);
    runReportJob($small);

    $cappedBook = readReportWorkbook($capped->refresh());
    $smallBook = readReportWorkbook($small->refresh());
    $cappedHeaders = $cappedBook[__('analytical_reports.sheet.requests')]['headers'];

    expect(end($cappedHeaders))->toBe(__('analytical_reports.columns.attachment', ['number' => 10]))
        ->and($cappedBook[__('analytical_reports.sheet.attachments')]['rows'])->toHaveCount(13)
        ->and($capped->attachments_count)->toBe(13)
        ->and(end($smallBook[__('analytical_reports.sheet.requests')]['headers']))->toBe(__('analytical_reports.columns.attachment', ['number' => 3]));
});

it('filters by settlement date using only settled settlements in the period', function (): void {
    $paid = PaymentRequest::factory()->forBranch($this->branch)->settled()->create();
    PaymentSettlementItem::factory()
        ->for(PaymentSettlement::factory()->forBranch($this->branch)->settled()->dated('2026-09-05')->create(), 'settlement')
        ->forPaymentRequest($paid)
        ->create();
    $draft = PaymentRequest::factory()->forBranch($this->branch)->launched()->create();
    PaymentSettlementItem::factory()
        ->for(PaymentSettlement::factory()->forBranch($this->branch)->dated('2026-09-06')->create(), 'settlement')
        ->forPaymentRequest($draft)
        ->create();
    $lastMonth = PaymentRequest::factory()->forBranch($this->branch)->settled()->create();
    PaymentSettlementItem::factory()
        ->for(PaymentSettlement::factory()->forBranch($this->branch)->settled()->dated('2026-08-30')->create(), 'settlement')
        ->forPaymentRequest($lastMonth)
        ->create();
    PaymentRequest::factory()->forBranch($this->branch)->requested()->create(['due_date' => '2026-09-10']);

    $report = AnalyticalReport::factory()->basis(ReportDateBasis::SettlementDate)->create(['created_by' => $this->operador->getKey()]);
    runReportJob($report);

    $rows = readReportWorkbook($report->refresh())[__('analytical_reports.sheet.requests')]['rows'];

    expect(array_column($rows, reportColumn('request_id')))->toBe([$paid->getKey()])
        ->and($rows[0][reportColumn('settled_amount')])->toBe((float) $paid->net_amount);
});

it('filters by request date using the São Paulo calendar day', function (): void {
    $lateEvening = PaymentRequest::factory()->forBranch($this->branch)->create(['created_at' => CarbonImmutable::parse('2026-09-14 22:30', 'America/Sao_Paulo')]);
    PaymentRequest::factory()->forBranch($this->branch)->create(['created_at' => CarbonImmutable::parse('2026-09-15 00:10', 'America/Sao_Paulo')]);

    $report = AnalyticalReport::factory()->basis(ReportDateBasis::RequestDate)->create([
        'created_by' => $this->operador->getKey(),
        'period_start' => '2026-09-14',
        'period_end' => '2026-09-14',
    ]);
    runReportJob($report);

    $rows = readReportWorkbook($report->refresh())[__('analytical_reports.sheet.requests')]['rows'];

    expect(array_column($rows, reportColumn('request_id')))->toBe([$lateEvening->getKey()]);
});

it('uses the requester visibility and fails without retry when the requester is unavailable', function (): void {
    $visible = PaymentRequest::factory()->forBranch(Branch::factory()->create())->create(['due_date' => '2026-09-10']);
    PaymentRequest::factory()->forBranch($this->branch)->create(['due_date' => '2026-09-11'])->delete();
    $report = AnalyticalReport::factory()->create(['created_by' => $this->operador->getKey()]);

    runReportJob($report);

    expect(array_column(readReportWorkbook($report->refresh())[__('analytical_reports.sheet.requests')]['rows'], reportColumn('request_id')))
        ->toBe([$visible->getKey()]);

    $inactive = User::factory()->operador()->inactive()->create();
    $demoted = User::factory()->cliente()->withBranches([$this->branch])->create();

    foreach ([$inactive, $demoted] as $requester) {
        $unavailable = AnalyticalReport::factory()->create(['created_by' => $requester->getKey()]);

        runReportJob($unavailable);

        expect($unavailable->refresh()->status)->toBe(AnalyticalReportStatus::Failed)
            ->and($unavailable->failure_reason)->toBe(__('analytical_reports.errors.requester_unavailable'))
            ->and($unavailable->path)->toBeNull();
    }

    Notification::assertNothingSentTo($inactive);
});

it('marks the report failed when storage rejects the file and lets it be retried', function (): void {
    Event::fake([AnalyticalReportGenerationFailed::class]);
    config([
        'filesystems.disks.reports-broken' => ['driver' => 'local', 'root' => '/proc/rjet-reports-unwritable', 'throw' => false],
        'rjet.reports.disk' => 'reports-broken',
    ]);
    PaymentRequest::factory()->forBranch($this->branch)->create(['due_date' => '2026-09-10']);
    $report = AnalyticalReport::factory()->create(['created_by' => $this->operador->getKey()]);

    expect(fn () => runReportJob($report))->toThrow(AnalyticalReportException::class);

    expect($report->refresh()->status)->toBe(AnalyticalReportStatus::Failed)
        ->and($report->failure_reason)->toBe(__('analytical_reports.errors.storage_write_failed'));
    Event::assertDispatchedTimes(AnalyticalReportGenerationFailed::class, 1);

    Queue::fake();
    app(AnalyticalReportService::class)->retry($report, $this->operador);

    expect($report->refresh()->status)->toBe(AnalyticalReportStatus::Queued)
        ->and($report->failure_reason)->toBeNull();
    Queue::assertPushedOn(config('rjet.reports.queue'), GenerateAnalyticalReportJob::class);
});

it('does not generate a report deleted while it was queued', function (): void {
    Event::fake([AnalyticalReportGenerated::class, AnalyticalReportGenerationFailed::class]);
    $report = AnalyticalReport::factory()->create(['created_by' => $this->operador->getKey()]);
    $report->delete();

    app(AnalyticalReportService::class)->generate($report);

    expect($report->refresh()->status)->toBe(AnalyticalReportStatus::Queued)
        ->and($report->trashed())->toBeTrue();
    Event::assertNotDispatched(AnalyticalReportGenerated::class);
    Event::assertNotDispatched(AnalyticalReportGenerationFailed::class);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('writes the payer account with the translated format', function (): void {
    app()->setLocale('en');
    $account = BranchBankAccount::factory()->create([
        'branch_id' => $this->branch->getKey(),
        'bank_code' => '341',
        'agency' => '1234',
        'account_number' => '56789',
        'account_digit' => '0',
    ]);
    $request = PaymentRequest::factory()->forBranch($this->branch)->launched()->create(['due_date' => '2026-09-10']);
    $settlement = PaymentSettlement::factory()->forAccount($account)->settled()->create();
    PaymentSettlementItem::factory()->for($settlement, 'settlement')->forPaymentRequest($request)->create();
    $report = AnalyticalReport::factory()->create(['created_by' => $this->operador->getKey()]);

    runReportJob($report);

    $row = readReportWorkbook($report->refresh())[__('analytical_reports.sheet.requests')]['rows'][0];

    expect($row[reportColumn('payer_account')])->toBe(__('analytical_reports.payer_account_format', [
        'bank' => '341',
        'agency' => '1234',
        'account' => '56789-0',
    ]));
});

it('does nothing for an already generated report', function (): void {
    Event::fake([AnalyticalReportGenerated::class, AnalyticalReportGenerationFailed::class]);
    $report = AnalyticalReport::factory()->generated()->create(['created_by' => $this->operador->getKey()]);
    $snapshot = $report->only(['status', 'path', 'rows_count', 'generated_at']);

    app(AnalyticalReportService::class)->generate($report);

    expect($report->refresh()->only(['status', 'path', 'rows_count', 'generated_at']))->toEqual($snapshot);
    Event::assertNotDispatched(AnalyticalReportGenerated::class);
    Event::assertNotDispatched(AnalyticalReportGenerationFailed::class);
});

it('dispatches a single generated event when two runs overlap', function (): void {
    Event::fake([AnalyticalReportGenerated::class]);
    PaymentRequest::factory()->forBranch($this->branch)->create(['due_date' => '2026-09-10']);
    $report = AnalyticalReport::factory()->create(['created_by' => $this->operador->getKey()]);

    app()->bind(SpreadsheetWriter::class, fn () => new class($report) extends OpenSpoutXlsxSpreadsheetWriterProxy {});

    app(AnalyticalReportService::class)->generate($report);

    expect(OpenSpoutXlsxSpreadsheetWriterProxy::$nested)->toBeTrue()
        ->and($report->refresh()->status)->toBe(AnalyticalReportStatus::Generated);
    Event::assertDispatchedTimes(AnalyticalReportGenerated::class, 1);
});

it('uses the configured queue and retry settings without middleware', function (): void {
    config(['rjet.reports.queue' => 'reports']);
    $job = new GenerateAnalyticalReportJob(AnalyticalReport::factory()->create());

    expect($job->queue)->toBe('reports')
        ->and($job->connection)->toBeNull()
        ->and($job->timeout)->toBe(75)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 30, 60])
        ->and($job->middleware)->toBe([])
        ->and(method_exists($job, 'middleware'))->toBeFalse();
});

it('writes every row once in due date, creation and id order across chunks', function (): void {
    $supplier = Supplier::factory()->create();
    $costCenter = CostCenter::factory()->forBranch($this->branch)->create();
    PaymentRequest::factory()
        ->count(510)
        ->sequence(fn ($sequence): array => [
            'due_date' => sprintf('2026-09-%02d', 30 - ($sequence->index % 30)),
            'created_at' => CarbonImmutable::parse('2026-09-01 08:00', 'America/Sao_Paulo')->addMinutes($sequence->index % 7),
        ])
        ->create(['branch_id' => $this->branch->getKey(), 'cost_center_id' => $costCenter->getKey(), 'supplier_id' => $supplier->getKey()]);
    $report = AnalyticalReport::factory()->create(['created_by' => $this->operador->getKey()]);

    runReportJob($report);

    $ids = array_column(readReportWorkbook($report->refresh())[__('analytical_reports.sheet.requests')]['rows'], reportColumn('request_id'));
    $expected = PaymentRequest::query()->orderBy('due_date')->orderBy('created_at')->orderBy('id')->pluck('id')->all();

    expect($report->rows_count)->toBe(510)
        ->and($ids)->toHaveCount(510)
        ->and(array_unique($ids))->toHaveCount(510)
        ->and($ids)->toBe($expected);
});

it('applies the stored company and status filters', function (): void {
    $launched = PaymentRequest::factory()->forBranch($this->branch)->launched()->create(['due_date' => '2026-09-10']);
    PaymentRequest::factory()->forBranch($this->branch)->requested()->create(['due_date' => '2026-09-11']);
    PaymentRequest::factory()->forBranch(Branch::factory()->create())->launched()->create(['due_date' => '2026-09-12']);
    $report = AnalyticalReport::factory()
        ->forCompany($this->branch->company)
        ->withStatuses(['launched'])
        ->create(['created_by' => $this->operador->getKey()]);

    runReportJob($report);

    expect(array_column(readReportWorkbook($report->refresh())[__('analytical_reports.sheet.requests')]['rows'], reportColumn('request_id')))
        ->toBe([$launched->getKey()])
        ->and($report->rows_count)->toBe(1);
});

it('fails without a file when the result grew past the row limit after the request', function (): void {
    Event::fake([AnalyticalReportGenerated::class, AnalyticalReportGenerationFailed::class]);
    config(['rjet.reports.max_rows' => 1]);
    PaymentRequest::factory()->forBranch($this->branch)->count(2)->create(['due_date' => '2026-09-10']);
    $report = AnalyticalReport::factory()->create(['created_by' => $this->operador->getKey()]);

    runReportJob($report);

    expect($report->refresh()->status)->toBe(AnalyticalReportStatus::Failed)
        ->and($report->failure_reason)->toBe(AnalyticalReportException::tooManyRows(2, 1)->getUserMessage())
        ->and($report->path)->toBeNull()
        ->and(Storage::disk('local')->allFiles('reports'))->toBe([]);
    Event::assertDispatchedTimes(AnalyticalReportGenerationFailed::class, 1);
    Event::assertNotDispatched(AnalyticalReportGenerated::class);
});

it('stores a generic reason when the job fails unexpectedly', function (): void {
    Event::fake([AnalyticalReportGenerationFailed::class]);
    $report = AnalyticalReport::factory()->generating()->create(['created_by' => $this->operador->getKey()]);

    (new GenerateAnalyticalReportJob($report))->failed(new RuntimeException('SQLSTATE[08006] connection to 10.0.0.5 refused'));

    expect($report->refresh()->status)->toBe(AnalyticalReportStatus::Failed)
        ->and($report->failure_reason)->toBe(__('analytical_reports.messages.generation_interrupted'));
    Event::assertDispatched(AnalyticalReportGenerationFailed::class, fn (AnalyticalReportGenerationFailed $event): bool => $event->report->is($report));
});

it('keeps a generated report when a late failure arrives', function (): void {
    Event::fake([AnalyticalReportGenerationFailed::class]);
    $report = AnalyticalReport::factory()->generated()->create(['created_by' => $this->operador->getKey()]);

    (new GenerateAnalyticalReportJob($report))->failed(new RuntimeException('timeout'));

    expect($report->refresh()->status)->toBe(AnalyticalReportStatus::Generated)
        ->and($report->failure_reason)->toBeNull();
    Event::assertNotDispatched(AnalyticalReportGenerationFailed::class);
});

/**
 * Runs a competing generation right before the outer run publishes, emulating two overlapping workers.
 */
abstract class OpenSpoutXlsxSpreadsheetWriterProxy implements SpreadsheetWriter
{
    public static bool $nested = false;

    private OpenSpoutXlsxSpreadsheetWriter $inner;

    public function __construct(private readonly AnalyticalReport $report)
    {
        $this->inner = new OpenSpoutXlsxSpreadsheetWriter;
    }

    public function open(string $absolutePath): void
    {
        $this->inner->open($absolutePath);
    }

    public function addSheet(string $name, array $headers): void
    {
        $this->inner->addSheet($name, $headers);
    }

    public function addRow(array $cells): void
    {
        $this->inner->addRow($cells);
    }

    public function close(): void
    {
        $this->inner->close();

        if (! self::$nested) {
            self::$nested = true;
            app(AnalyticalReportService::class)->generate($this->report);
        }
    }
}

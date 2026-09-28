<?php

declare(strict_types=1);

use App\Models\AnalyticalReport;
use App\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the analytical reports table with the expected columns and defaults', function (): void {
    $columns = collect(Schema::getColumns('analytical_reports'))->keyBy('name');

    expect($columns->keys()->sort()->values()->all())->toBe(collect([
        'id', 'status', 'company_id', 'branch_id', 'date_basis', 'period_start', 'period_end', 'statuses',
        'disk', 'path', 'filename', 'size', 'rows_count', 'attachments_count', 'failure_reason',
        'started_at', 'generated_at', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
    ])->sort()->values()->all())
        ->and($columns['status']['default'])->toContain('queued')
        ->and($columns['date_basis']['default'])->toContain('due_date')
        ->and($columns['rows_count']['default'])->toContain('0')
        ->and($columns['attachments_count']['default'])->toContain('0');
});

it('restricts hard deletes of referenced branches', function (): void {
    $branch = Branch::factory()->create();
    AnalyticalReport::factory()->forBranch($branch)->create();

    expect(fn () => DB::table('branches')->where('id', $branch->getKey())->delete())->toThrow(QueryException::class);
});

it('does not add a created_by and created_at composite index', function (): void {
    $composite = collect(Schema::getIndexes('analytical_reports'))
        ->first(fn (array $index): bool => $index['columns'] === ['created_by', 'created_at']);

    expect($composite)->toBeNull();
});

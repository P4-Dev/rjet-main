<?php

declare(strict_types=1);

use App\Enums\CnabFileStatus;
use App\Models\CnabConfig;
use App\Models\CnabFile;
use App\Models\CnabFileItem;
use App\Models\PaymentSettlement;
use App\Models\PaymentSettlementItem;
use App\Models\User;
use App\Services\PaymentSettlementService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

$partialIndexesOnly = fn (): bool => ! in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);

it('allows only one active item per payment request', function (): void {
    $item = PaymentSettlementItem::factory()->create();
    $otherSettlement = PaymentSettlement::factory()->forBranch($item->settlement->branch)->create();

    expect(fn () => PaymentSettlementItem::factory()->for($otherSettlement, 'settlement')->forPaymentRequest($item->paymentRequest)->create())
        ->toThrow(UniqueConstraintViolationException::class);

    $item->forceFill(['released_at' => now()])->save();

    expect(PaymentSettlementItem::factory()->for($otherSettlement, 'settlement')->forPaymentRequest($item->paymentRequest)->create()->exists)->toBeTrue();
})->skip($partialIndexesOnly, 'Partial indexes require pgsql/sqlite');

it('keeps the account bound to an inactive config and frees it after soft delete', function (): void {
    $config = CnabConfig::factory()->inactive()->create();

    expect(fn () => CnabConfig::factory()->forAccount($config->branchBankAccount)->create())
        ->toThrow(UniqueConstraintViolationException::class);

    $config->delete();

    expect(CnabConfig::factory()->forAccount($config->branchBankAccount)->create()->exists)->toBeTrue();
})->skip($partialIndexesOnly, 'Partial indexes require pgsql/sqlite');

it('allows a single active file per settlement while failed and superseded coexist', function (): void {
    $settlement = PaymentSettlement::factory()->create();
    CnabFile::factory()->for($settlement, 'settlement')->failed()->create();
    CnabFile::factory()->for($settlement, 'settlement')->superseded()->create();
    CnabFile::factory()->for($settlement, 'settlement')->generated()->create();

    expect(fn () => CnabFile::factory()->for($settlement, 'settlement')->queued()->create())
        ->toThrow(UniqueConstraintViolationException::class);
})->skip($partialIndexesOnly, 'Partial indexes require pgsql/sqlite');

it('never reuses a file sequence of a soft deleted file', function (): void {
    $file = CnabFile::factory()->failed()->create(['file_sequence' => 5]);
    $file->delete();
    $otherSettlement = PaymentSettlement::factory()->forAccount($file->settlement->branchBankAccount)->create();

    expect(fn () => CnabFile::factory()->for($otherSettlement, 'settlement')->create([
        'cnab_config_id' => $file->cnab_config_id,
        'file_sequence' => 5,
    ]))->toThrow(UniqueConstraintViolationException::class);
})->skip($partialIndexesOnly, 'Partial indexes require pgsql/sqlite');

it('keeps file items unique per settlement item and reference', function (array $duplicate): void {
    $item = CnabFileItem::factory()->create();

    expect(fn () => CnabFileItem::factory()->create([
        'cnab_file_id' => $item->cnab_file_id,
        ...array_intersect_key($item->getAttributes(), array_flip($duplicate)),
    ]))->toThrow(UniqueConstraintViolationException::class);
})->with([
    'settlement item' => [['payment_settlement_item_id']],
    'reference' => [['reference']],
]);

it('declares the active file index literals exactly as the enum active values', function (): void {
    $definition = match (DB::getDriverName()) {
        'sqlite' => DB::scalar("SELECT sql FROM sqlite_master WHERE name = 'cnab_files_settlement_active_unique'"),
        'pgsql' => DB::scalar("SELECT indexdef FROM pg_indexes WHERE indexname = 'cnab_files_settlement_active_unique'"),
    };

    preg_match('/IN\s*\(([^)]*)\)/i', (string) $definition, $matches);
    preg_match_all("/'([^']+)'/", $matches[1] ?? '', $literals);
    $expected = CnabFileStatus::activeValues();
    sort($expected);
    $actual = $literals[1];
    sort($actual);

    expect($actual)->toBe($expected);
})->skip($partialIndexesOnly, 'Partial indexes require pgsql/sqlite');

it('force deletes a cancelled settlement with its trashed files and stored remittance', function (): void {
    Storage::fake('local');
    $adm = User::factory()->adm()->create();
    $settlement = PaymentSettlement::factory()->cancelled()->withItems(1)->create();
    $file = CnabFile::factory()->for($settlement, 'settlement')->superseded()->create();
    CnabFileItem::factory()->for($file, 'file')->create(['payment_settlement_item_id' => $settlement->items()->value('id')]);
    Storage::disk('local')->put($file->path, 'remittance');
    $service = app(PaymentSettlementService::class);

    $service->delete($settlement, $adm);
    expect($file->fresh()->trashed())->toBeTrue();

    $service->forceDelete($settlement->fresh(), $adm);

    expect(PaymentSettlement::withTrashed()->whereKey($settlement->getKey())->exists())->toBeFalse()
        ->and(PaymentSettlementItem::query()->where('payment_settlement_id', $settlement->getKey())->exists())->toBeFalse()
        ->and(CnabFile::withTrashed()->whereKey($file->getKey())->exists())->toBeFalse()
        ->and(CnabFileItem::query()->where('cnab_file_id', $file->getKey())->exists())->toBeFalse();
    Storage::disk('local')->assertMissing($file->path);
});

it('stores file items without updated_at', function (): void {
    $item = CnabFileItem::factory()->create();

    $item->forceFill(['is_valid' => false])->save();

    expect(Schema::hasColumn('cnab_file_items', 'updated_at'))->toBeFalse()
        ->and($item->fresh()->is_valid)->toBeFalse();
});

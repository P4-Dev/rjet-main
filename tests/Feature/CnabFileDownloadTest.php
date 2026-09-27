<?php

declare(strict_types=1);

use App\Actions\Cnab\DownloadCnabFileAction;
use App\Enums\CnabFileStatus;
use App\Events\Cnab\CnabFileDownloaded;
use App\Exceptions\CnabException;
use App\Exceptions\PaymentSettlementException;
use App\Models\CnabFile;
use App\Models\User;
use App\Services\CnabFileService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

function generatedCnabFile(): CnabFile
{
    return app(CnabFileService::class)
        ->request(createCnabReadySettlement(), User::factory()->operador()->create())
        ->fresh();
}

function downloadCnabFile(CnabFile $file, User $actor): StreamedResponse
{
    return app(DownloadCnabFileAction::class)($file, $actor, '10.0.0.1');
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.cnab.disk' => 'local']);
});

it('streams the file with its name to staff', function (string $role): void {
    $file = generatedCnabFile();

    $response = downloadCnabFile($file, User::factory()->{$role}()->create());

    expect($response->headers->get('Content-Disposition'))->toContain($file->filename);
    ob_start();
    $response->sendContent();
    expect(ob_get_clean())->toBe(Storage::disk('local')->get($file->path));
})->with(['operador', 'adm']);

it('forbids cliente from downloading', function (): void {
    $file = generatedCnabFile();

    expect(fn () => downloadCnabFile($file, User::factory()->cliente()->withBranches(1)->create()))
        ->toThrow(PaymentSettlementException::class, PaymentSettlementException::unauthorized()->getMessage());
});

it('refuses files that are not generated', function (CnabFileStatus $status): void {
    $file = generatedCnabFile();
    $file->forceFill(['status' => $status])->save();

    expect(fn () => app(CnabFileService::class)->download($file, User::factory()->operador()->create()))
        ->toThrow(CnabException::class, CnabException::fileNotDownloadable()->getMessage());
})->with([CnabFileStatus::Superseded, CnabFileStatus::Failed]);

it('reports a friendly error when the file is missing from storage', function (): void {
    $file = generatedCnabFile();
    Storage::disk('local')->delete($file->path);

    expect(fn () => downloadCnabFile($file, User::factory()->operador()->create()))
        ->toThrow(CnabException::class, CnabException::fileMissing()->getMessage());
});

it('refuses a file whose checksum no longer matches', function (): void {
    $file = generatedCnabFile();
    Storage::disk('local')->put($file->path, 'tampered');

    expect(fn () => downloadCnabFile($file, User::factory()->operador()->create()))
        ->toThrow(CnabException::class, CnabException::fileIntegrityCheckFailed()->getMessage());
});

it('records only the first download and dispatches an event for every download', function (): void {
    Event::fake([CnabFileDownloaded::class]);
    $file = generatedCnabFile();
    $first = User::factory()->operador()->create();
    $second = User::factory()->adm()->create();

    downloadCnabFile($file, $first);
    downloadCnabFile($file->fresh(), $second);

    expect($file->fresh()->downloaded_by)->toBe($first->getKey())
        ->and($file->fresh()->downloaded_at)->not->toBeNull();
    Event::assertDispatchedTimes(CnabFileDownloaded::class, 2);
    Event::assertDispatched(CnabFileDownloaded::class, fn (CnabFileDownloaded $event): bool => $event->user->is($second) && $event->ipAddress === '10.0.0.1');
});

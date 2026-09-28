<?php

declare(strict_types=1);

use App\Filament\Resources\AnalyticalReports\Pages\ViewAnalyticalReport;
use App\Models\AnalyticalReport;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(function (): void {
    Storage::fake('local');
    config(['rjet.reports.disk' => 'local']);
});

function storedReport(AnalyticalReport $report): AnalyticalReport
{
    Storage::disk('local')->put($report->path, 'xlsx-bytes');

    return $report;
}

it('prunes expired reports and their files, including soft-deleted ones', function (): void {
    $expired = storedReport(AnalyticalReport::factory()->generated()->expired()->create());
    $expiredTrashed = storedReport(AnalyticalReport::factory()->generated()->expired()->create());
    $expiredTrashed->delete();
    $expiredWithoutFile = AnalyticalReport::factory()->failed()->expired()->create();
    $recent = storedReport(AnalyticalReport::factory()->generated()->create());

    artisan('model:prune', ['--model' => [AnalyticalReport::class]])->assertSuccessful();

    expect(AnalyticalReport::withTrashed()->pluck('id')->all())->toBe([$recent->getKey()]);
    Storage::disk('local')->assertMissing($expired->path);
    Storage::disk('local')->assertMissing($expiredTrashed->path);
    Storage::disk('local')->assertExists($recent->path);
    expect($expiredWithoutFile->path)->toBeNull();
});

it('deletes the file when an admin force deletes the report', function (): void {
    actingAs(User::factory()->adm()->create());
    $report = storedReport(AnalyticalReport::factory()->generated()->create());
    $report->delete();

    Livewire::test(ViewAnalyticalReport::class, ['record' => $report->getKey()])
        ->callAction(TestAction::make('forceDelete'));

    expect(AnalyticalReport::withTrashed()->whereKey($report->getKey())->exists())->toBeFalse();
    Storage::disk('local')->assertMissing($report->path);
});

it('removes reports and files when their company or branch is force deleted', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $byCompany = storedReport(AnalyticalReport::factory()->generated()->forCompany($company)->create());
    $byBranch = storedReport(AnalyticalReport::factory()->generated()->forBranch($branch)->create());
    $isolatedBranch = Branch::factory()->create();
    $byIsolatedBranch = storedReport(AnalyticalReport::factory()->generated()->forBranch($isolatedBranch)->create());
    $unrelated = storedReport(AnalyticalReport::factory()->generated()->create());

    $company->forceDelete();
    $isolatedBranch->forceDelete();

    expect(AnalyticalReport::withTrashed()->pluck('id')->all())->toBe([$unrelated->getKey()]);
    Storage::disk('local')->assertMissing($byCompany->path);
    Storage::disk('local')->assertMissing($byBranch->path);
    Storage::disk('local')->assertMissing($byIsolatedBranch->path);
});

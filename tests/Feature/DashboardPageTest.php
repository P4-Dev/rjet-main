<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('renders the dashboard for every role', function (string $role): void {
    $user = User::factory()->{$role}()->withBranches(1)->create();
    actingAs($user);

    get(Dashboard::getUrl())->assertOk()->assertSee(__('dashboard.title'));
})->with(['cliente', 'operador', 'adm']);

it('limits company and branch options to the client branches', function (): void {
    $ownCompany = Company::factory()->create(['name' => 'Empresa Própria']);
    $ownBranch = Branch::factory()->for($ownCompany)->create(['name' => 'Filial Própria']);
    $otherBranch = Branch::factory()->for(Company::factory()->create(['name' => 'Empresa Alheia']))->create(['name' => 'Filial Alheia']);
    actingAs(User::factory()->cliente()->withBranches([$ownBranch])->create());

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertFormFieldExists('company_id', 'filtersForm', fn (Select $field): bool => $field->getOptions() === [$ownCompany->getKey() => 'Empresa Própria'])
        ->assertFormFieldExists('branch_id', 'filtersForm', fn (Select $field): bool => $field->getOptions() === [$ownBranch->getKey() => 'Filial Própria'])
        ->assertDontSee($otherBranch->name);
});

it('shows the report action only to staff', function (string $role, bool $visible): void {
    actingAs(User::factory()->{$role}()->withBranches(1)->create());

    $component = Livewire::test(Dashboard::class);

    $visible
        ? $component->assertActionVisible('requestAnalyticalReport')
        : $component->assertActionHidden('requestAnalyticalReport');
})->with([
    'cliente' => ['cliente', false],
    'operador' => ['operador', true],
    'adm' => ['adm', true],
]);

it('survives malformed filters in the query string', function (): void {
    actingAs(User::factory()->operador()->create());

    get(Dashboard::getUrl().'?filters[period_start]=nao-e-data&filters[branch_id]=x')->assertOk();
});

<?php

declare(strict_types=1);

use App\Models\Appropriation;
use App\Models\Bank;
use App\Models\CostCenter;
use App\Models\Supplier;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('enforces managerial registration policy matrix', function (string $role, string $ability, bool $expected): void {
    $user = User::factory()->{$role}()->create();

    expect($user->can($ability, CostCenter::class))->toBe($expected)
        ->and($user->can($ability, Appropriation::class))->toBe($expected)
        ->and($user->can($ability, Supplier::class))->toBe($expected)
        ->and($user->can($ability, Bank::class))->toBe($expected);
})->with([
    'adm can viewAny' => ['adm', 'viewAny', true],
    'operador can viewAny' => ['operador', 'viewAny', true],
    'cliente cannot viewAny' => ['cliente', 'viewAny', false],
    'adm can create' => ['adm', 'create', true],
    'operador cannot create' => ['operador', 'create', false],
    'cliente cannot create' => ['cliente', 'create', false],
]);

it('grants Filament listing of phase 2 resources to admins', function (): void {
    actingAs(User::factory()->adm()->create());

    $this->get(route('filament.admin.resources.cost-centers.index'))->assertOk();
    $this->get(route('filament.admin.resources.appropriations.index'))->assertOk();
    $this->get(route('filament.admin.resources.suppliers.index'))->assertOk();
    $this->get(route('filament.admin.resources.banks.index'))->assertOk();
});

it('lets operators read phase 2 resources', function (): void {
    actingAs(User::factory()->operador()->create());

    $this->get(route('filament.admin.resources.cost-centers.index'))->assertOk();
    $this->get(route('filament.admin.resources.appropriations.index'))->assertOk();
    $this->get(route('filament.admin.resources.suppliers.index'))->assertOk();
    $this->get(route('filament.admin.resources.banks.index'))->assertOk();
});

it('denies client access to phase 2 resources', function (): void {
    actingAs(User::factory()->cliente()->create());

    $this->get(route('filament.admin.resources.cost-centers.index'))->assertForbidden();
    $this->get(route('filament.admin.resources.appropriations.index'))->assertForbidden();
    $this->get(route('filament.admin.resources.suppliers.index'))->assertForbidden();
    $this->get(route('filament.admin.resources.banks.index'))->assertForbidden();
});

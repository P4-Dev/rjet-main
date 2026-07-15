<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('enforces the company/branch policy matrix per role', function (string $role, string $ability, bool $expected): void {
    $user = User::factory()->{$role}()->create();

    expect($user->can($ability, Company::class))->toBe($expected)
        ->and($user->can($ability, Branch::class))->toBe($expected);
})->with([
    'adm can viewAny' => ['adm', 'viewAny', true],
    'operador can viewAny' => ['operador', 'viewAny', true],
    'cliente cannot viewAny' => ['cliente', 'viewAny', false],
    'adm can create' => ['adm', 'create', true],
    'operador cannot create' => ['operador', 'create', false],
    'cliente cannot create' => ['cliente', 'create', false],
]);

it('restricts every user ability to admins', function (string $role, bool $expected): void {
    $actor = User::factory()->{$role}()->create();
    $target = User::factory()->cliente()->create();

    expect($actor->can('viewAny', User::class))->toBe($expected)
        ->and($actor->can('update', $target))->toBe($expected);
})->with([
    'adm' => ['adm', true],
    'operador' => ['operador', false],
    'cliente' => ['cliente', false],
]);

it('prevents an admin from deleting themselves through the policy', function (): void {
    $admin = User::factory()->adm()->create();
    User::factory()->adm()->create();

    expect($admin->can('delete', $admin))->toBeFalse();
});

it('prevents deleting the last active admin through the policy', function (): void {
    $actor = User::factory()->adm()->create();
    $target = User::factory()->adm()->create();
    $actor->update(['is_active' => false]);

    expect($actor->can('delete', $target))->toBeFalse();
});

it('allows deleting a non-last admin through the policy', function (): void {
    $actor = User::factory()->adm()->create();
    $target = User::factory()->adm()->create();

    expect($actor->can('delete', $target))->toBeTrue();
});

it('grants Filament resource listing to admins', function (): void {
    actingAs(User::factory()->adm()->create());

    $this->get(route('filament.admin.resources.companies.index'))->assertOk();
    $this->get(route('filament.admin.resources.branches.index'))->assertOk();
    $this->get(route('filament.admin.resources.users.index'))->assertOk();
});

it('denies client access to registration resources (403)', function (): void {
    actingAs(User::factory()->cliente()->create());

    $this->get(route('filament.admin.resources.companies.index'))->assertForbidden();
    $this->get(route('filament.admin.resources.branches.index'))->assertForbidden();
    $this->get(route('filament.admin.resources.users.index'))->assertForbidden();
});

it('lets operators read companies/branches but not users', function (): void {
    actingAs(User::factory()->operador()->create());

    $this->get(route('filament.admin.resources.companies.index'))->assertOk();
    $this->get(route('filament.admin.resources.branches.index'))->assertOk();
    $this->get(route('filament.admin.resources.users.index'))->assertForbidden();
});

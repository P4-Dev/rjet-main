<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Exceptions\UserException;
use App\Models\Branch;
use App\Models\User;
use App\Services\UserService;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->service = app(UserService::class);
});

it('creates a user and syncs branches with a single default', function (): void {
    $branches = Branch::factory()->count(2)->create();

    $user = $this->service->create(
        [
            'name' => 'Novo Cliente',
            'email' => 'novo@rjet.com',
            'password' => 'password',
            'role' => UserRole::Cliente,
        ],
        $branches->modelKeys(),
        $branches->last()->getKey(),
    );

    expect($user->branches()->count())->toBe(2)
        ->and($user->defaultBranch()->count())->toBe(1)
        ->and($user->defaultBranch()->first()->getKey())->toBe($branches->last()->getKey());
});

it('keeps only one default branch when re-syncing', function (): void {
    $user = User::factory()->cliente()->create();
    $branches = Branch::factory()->count(3)->create();

    $this->service->syncBranches($user, $branches->modelKeys(), $branches->first()->getKey());
    $this->service->syncBranches($user, $branches->modelKeys(), $branches->last()->getKey());

    expect($user->defaultBranch()->count())->toBe(1)
        ->and($user->defaultBranch()->first()->getKey())->toBe($branches->last()->getKey());
});

it('detaches branches removed from the sync list', function (): void {
    $user = User::factory()->cliente()->create();
    $branches = Branch::factory()->count(3)->create();

    $this->service->syncBranches($user, $branches->modelKeys());
    $this->service->syncBranches($user, [$branches->first()->getKey()]);

    expect($user->branches()->count())->toBe(1);
});

it('blocks an admin from deleting themselves', function (): void {
    $admin = User::factory()->adm()->create();
    actingAs($admin);

    expect(fn () => $this->service->delete($admin))
        ->toThrow(UserException::class);

    expect(User::query()->whereKey($admin->getKey())->exists())->toBeTrue();
});

it('blocks deleting the last active admin', function (): void {
    $actor = User::factory()->adm()->create();
    $target = User::factory()->adm()->create();
    actingAs($actor);

    // Remove o "actor" da contagem deixando apenas o target como último adm ativo.
    $actor->update(['is_active' => false]);

    expect(fn () => $this->service->delete($target))
        ->toThrow(UserException::class);
});

it('allows deleting an admin when another active admin remains', function (): void {
    $actor = User::factory()->adm()->create();
    $target = User::factory()->adm()->create();
    actingAs($actor);

    $this->service->delete($target);

    expect(User::query()->whereKey($target->getKey())->exists())->toBeFalse();
});

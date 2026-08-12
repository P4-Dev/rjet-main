<?php

declare(strict_types=1);

use App\Filament\Resources\ApprovalRules\Pages\CreateApprovalRule;
use App\Filament\Resources\ApprovalRules\Pages\ListApprovalRules;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('allows adm to access approval rule list and create', function (): void {
    $adm = User::factory()->adm()->create();
    actingAs($adm);

    Livewire::test(ListApprovalRules::class)->assertSuccessful();
    Livewire::test(CreateApprovalRule::class)->assertSuccessful();
});

it('forbids operador and cliente from approval rule resource', function (): void {
    $operador = User::factory()->operador()->create();
    actingAs($operador);

    Livewire::test(ListApprovalRules::class)->assertForbidden();

    $cliente = User::factory()->cliente()->create();
    actingAs($cliente);

    Livewire::test(ListApprovalRules::class)->assertForbidden();
});

<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('loads the unread filament database notification count', function (): void {
    actingAs(User::factory()->adm()->create());

    Livewire::test(DatabaseNotifications::class)
        ->assertOk();
});

it('finds unread filament database notifications by json format', function (): void {
    $user = User::factory()->adm()->create();

    actingAs($user);

    Notification::make()
        ->title('Solicitação aprovada')
        ->sendToDatabase($user);

    expect($user->unreadNotifications()->where('data->format', 'filament')->count())->toBe(1);

    Livewire::test(DatabaseNotifications::class)
        ->assertOk()
        ->assertSee('Solicitação aprovada');
});

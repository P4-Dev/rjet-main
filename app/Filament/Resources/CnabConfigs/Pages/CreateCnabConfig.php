<?php

declare(strict_types=1);

namespace App\Filament\Resources\CnabConfigs\Pages;

use App\Exceptions\BusinessException;
use App\Filament\Resources\CnabConfigs\CnabConfigResource;
use App\Models\CnabConfig;
use App\Services\CnabConfigService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateCnabConfig extends CreateRecord
{
    protected static string $resource = CnabConfigResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = Filament::auth()->user();
        abort_unless($user !== null, 403);

        try {
            return app(CnabConfigService::class)->create($data, $user);
        } catch (BusinessException $exception) {
            Notification::make()
                ->title($exception->getUserMessage())
                ->danger()
                ->send();

            $this->halt();

            return new CnabConfig;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('cnab_configs.messages.created');
    }
}

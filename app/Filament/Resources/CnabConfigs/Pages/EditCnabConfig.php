<?php

declare(strict_types=1);

namespace App\Filament\Resources\CnabConfigs\Pages;

use App\Exceptions\BusinessException;
use App\Filament\Resources\CnabConfigs\CnabConfigResource;
use App\Models\CnabConfig;
use App\Services\CnabConfigService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditCnabConfig extends EditRecord
{
    protected static string $resource = CnabConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->modalDescription(__('cnab_configs.messages.delete_replaces'))
                ->using(function (CnabConfig $record, DeleteAction $action): bool {
                    try {
                        app(CnabConfigService::class)->delete($record, Filament::auth()->user());

                        return true;
                    } catch (BusinessException $exception) {
                        Notification::make()
                            ->title($exception->getUserMessage())
                            ->danger()
                            ->send();

                        $action->halt();

                        return false;
                    }
                }),
        ];
    }

    /**
     * @param  CnabConfig  $record
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = Filament::auth()->user();
        abort_unless($user !== null, 403);

        try {
            return app(CnabConfigService::class)->update($record, $data, $user);
        } catch (BusinessException $exception) {
            Notification::make()
                ->title($exception->getUserMessage())
                ->danger()
                ->send();

            $this->halt();

            return $record;
        }
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('cnab_configs.messages.updated');
    }
}

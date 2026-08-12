<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApprovalRules\Pages;

use App\DTOs\ApprovalRuleData;
use App\Exceptions\BusinessException;
use App\Filament\Resources\ApprovalRules\ApprovalRuleResource;
use App\Services\ApprovalRuleService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

final class EditApprovalRule extends EditRecord
{
    protected static string $resource = ApprovalRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->using(fn ($record): mixed => app(ApprovalRuleService::class)->delete($record)),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        $user = Filament::auth()->user();
        abort_unless($user !== null, 403);

        try {
            $overlaps = app(ApprovalRuleService::class)->findOverlapping(
                (string) $data['branch_id'],
                number_format((float) $data['min_amount'], 2, '.', ''),
                isset($data['max_amount']) && $data['max_amount'] !== '' && $data['max_amount'] !== null
                    ? number_format((float) $data['max_amount'], 2, '.', '')
                    : null,
                (string) $record->getKey(),
            );

            if ($overlaps->isNotEmpty()) {
                Notification::make()
                    ->title(__('approval_rules.messages.overlap_warning'))
                    ->warning()
                    ->send();
            }

            return app(ApprovalRuleService::class)->update(
                $record,
                ApprovalRuleData::fromArray($data),
                $user,
            );
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
        return __('approval_rules.messages.updated');
    }
}

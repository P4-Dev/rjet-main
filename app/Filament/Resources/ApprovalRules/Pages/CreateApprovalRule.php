<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApprovalRules\Pages;

use App\DTOs\ApprovalRuleData;
use App\Exceptions\BusinessException;
use App\Filament\Resources\ApprovalRules\ApprovalRuleResource;
use App\Models\ApprovalRule;
use App\Services\ApprovalRuleService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateApprovalRule extends CreateRecord
{
    protected static string $resource = ApprovalRuleResource::class;

    protected function handleRecordCreation(array $data): Model
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
            );

            if ($overlaps->isNotEmpty()) {
                Notification::make()
                    ->title(__('approval_rules.messages.overlap_warning'))
                    ->warning()
                    ->send();
            }

            return app(ApprovalRuleService::class)->create(
                ApprovalRuleData::fromArray($data),
                $user,
            );
        } catch (BusinessException $exception) {
            Notification::make()
                ->title($exception->getUserMessage())
                ->danger()
                ->send();

            $this->halt();

            return new ApprovalRule;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('approval_rules.messages.created');
    }
}

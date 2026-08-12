<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApprovalRules\Pages;

use App\Filament\Resources\ApprovalRules\ApprovalRuleResource;
use App\Services\ApprovalRuleService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewApprovalRule extends ViewRecord
{
    protected static string $resource = ApprovalRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make()
                ->using(fn ($record): mixed => app(ApprovalRuleService::class)->delete($record)),
        ];
    }
}

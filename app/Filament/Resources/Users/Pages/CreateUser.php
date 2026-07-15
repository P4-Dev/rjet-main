<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\UserService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $branchIds = array_map('strval', $data['branches'] ?? []);
        $defaultBranch = $data['default_branch'] ?? null;

        unset($data['branches'], $data['default_branch']);

        return app(UserService::class)->create($data, $branchIds, $defaultBranch);
    }
}

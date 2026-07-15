<?php

declare(strict_types=1);

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class BlameableObserver
{
    public function creating(Model $model): void
    {
        $userId = Auth::id();

        // Sem contexto autenticado (seeders/console) não sobrescreve autoria.
        if ($userId === null) {
            return;
        }

        if (empty($model->getAttribute('created_by'))) {
            $model->setAttribute('created_by', $userId);
        }

        if (empty($model->getAttribute('updated_by'))) {
            $model->setAttribute('updated_by', $userId);
        }
    }

    public function updating(Model $model): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        $model->setAttribute('updated_by', $userId);
    }
}

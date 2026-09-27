<?php

declare(strict_types=1);

namespace App\Events\Cnab;

use App\Models\CnabFile;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CnabFileDownloaded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly CnabFile $file,
        public readonly User $user,
        public readonly ?string $ipAddress = null,
    ) {}
}

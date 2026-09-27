<?php

declare(strict_types=1);

namespace App\Events\Cnab;

use App\Models\CnabFile;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CnabFileGenerated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly CnabFile $file,
    ) {}
}

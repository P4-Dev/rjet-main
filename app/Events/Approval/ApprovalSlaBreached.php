<?php

declare(strict_types=1);

namespace App\Events\Approval;

use App\Models\Approval;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ApprovalSlaBreached
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Approval $approval,
    ) {}
}

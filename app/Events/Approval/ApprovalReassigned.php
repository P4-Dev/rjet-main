<?php

declare(strict_types=1);

namespace App\Events\Approval;

use App\Models\Approval;
use App\Models\ApprovalReassignment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ApprovalReassigned
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Approval $approval,
        public readonly ApprovalReassignment $reassignment,
    ) {}
}

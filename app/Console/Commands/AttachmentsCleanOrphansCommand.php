<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AttachmentService;
use Illuminate\Console\Command;

final class AttachmentsCleanOrphansCommand extends Command
{
    protected $signature = 'attachments:clean-orphans
                            {--hours= : Override staging TTL in hours}
                            {--dry-run : List files that would be deleted without deleting}';

    protected $description = 'Remove expired staging uploads and unreferenced attachment files';

    public function handle(AttachmentService $attachments): int
    {
        $hours = $this->option('hours');
        $dryRun = (bool) $this->option('dry-run');

        $result = $attachments->cleanOrphans(
            dryRun: $dryRun,
            stagingTtlHours: filled($hours) ? (int) $hours : null,
        );

        $prefix = $dryRun ? 'Would delete' : 'Deleted';

        $this->info("{$prefix} staging files: {$result['staging_deleted']}");
        $this->info("{$prefix} orphan files: {$result['orphan_deleted']}");

        return self::SUCCESS;
    }
}

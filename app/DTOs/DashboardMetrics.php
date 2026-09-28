<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DashboardMetrics
{
    public function __construct(
        public string $paidAmount,
        public int $paidCount,
        public string $openAmount,
        public int $openCount,
        public int $requestedCount,
        public int $launchedCount,
        public string $upcomingAmount,
        public int $upcomingCount,
        public string $overdueAmount,
        public int $overdueCount,
    ) {}

    /**
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            paidAmount: (string) $data['paidAmount'],
            paidCount: (int) $data['paidCount'],
            openAmount: (string) $data['openAmount'],
            openCount: (int) $data['openCount'],
            requestedCount: (int) $data['requestedCount'],
            launchedCount: (int) $data['launchedCount'],
            upcomingAmount: (string) $data['upcomingAmount'],
            upcomingCount: (int) $data['upcomingCount'],
            overdueAmount: (string) $data['overdueAmount'],
            overdueCount: (int) $data['overdueCount'],
        );
    }
}

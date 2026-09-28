<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DashboardSeries
{
    /**
     * @param  list<string>  $labels
     * @param  list<string>  $paid
     * @param  list<string>  $upcoming
     * @param  list<string>  $overdue
     */
    public function __construct(
        public array $labels,
        public array $paid,
        public array $upcoming,
        public array $overdue,
    ) {}

    public function isEmpty(): bool
    {
        if ($this->labels === []) {
            return true;
        }

        foreach ([...$this->paid, ...$this->upcoming, ...$this->overdue] as $value) {
            if (bccomp($value, '0', 2) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{labels: list<string>, paid: list<string>, upcoming: list<string>, overdue: list<string>}
     */
    public function toArray(): array
    {
        return [
            'labels' => $this->labels,
            'paid' => $this->paid,
            'upcoming' => $this->upcoming,
            'overdue' => $this->overdue,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            labels: array_values($data['labels'] ?? []),
            paid: array_values($data['paid'] ?? []),
            upcoming: array_values($data['upcoming'] ?? []),
            overdue: array_values($data['overdue'] ?? []),
        );
    }
}

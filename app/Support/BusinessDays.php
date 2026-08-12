<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;

final class BusinessDays
{
    /**
     * Add N future business days (Mon–Fri) from $from. Holidays are NOT skipped (F4).
     */
    public static function add(DateTimeInterface $from, int $businessDays): CarbonInterface
    {
        if ($businessDays < 1) {
            throw new InvalidArgumentException('Business days must be at least 1.');
        }

        $date = Carbon::instance($from)->copy();

        $added = 0;
        while ($added < $businessDays) {
            $date->addDay();

            if ($date->isWeekday()) {
                $added++;
            }
        }

        return $date;
    }
}

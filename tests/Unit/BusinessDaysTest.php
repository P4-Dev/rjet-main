<?php

declare(strict_types=1);

use App\Support\BusinessDays;
use Carbon\Carbon;
use Tests\TestCase;

uses(TestCase::class);

it('adds one business day from friday to monday', function (): void {
    $friday = Carbon::parse('2026-08-07 10:00:00'); // Friday

    expect(BusinessDays::add($friday, 1)->toDateString())->toBe('2026-08-10');
});

it('adds two business days from friday to tuesday', function (): void {
    $friday = Carbon::parse('2026-08-07 10:00:00');

    expect(BusinessDays::add($friday, 2)->toDateString())->toBe('2026-08-11');
});

it('does not count saturday and sunday', function (): void {
    $thursday = Carbon::parse('2026-08-06 09:00:00');

    expect(BusinessDays::add($thursday, 1)->toDateString())->toBe('2026-08-07')
        ->and(BusinessDays::add($thursday, 2)->toDateString())->toBe('2026-08-10');
});

it('does not skip brazilian holidays in F4', function (): void {
    // 2026-09-07 is Independence Day (Monday) — F4 still counts it as a business day
    $friday = Carbon::parse('2026-09-04 10:00:00');

    expect(BusinessDays::add($friday, 1)->toDateString())->toBe('2026-09-07');
});

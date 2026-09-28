<?php

declare(strict_types=1);

use App\Enums\AnalyticalReportStatus;
use App\Enums\PaymentDueSituation;
use App\Enums\PaymentRequestStatus;
use App\Models\PaymentRequest;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

describe('AnalyticalReportStatus', function (): void {
    it('allows only the documented transitions', function (AnalyticalReportStatus $from, array $allowed): void {
        foreach (AnalyticalReportStatus::cases() as $target) {
            expect($from->canTransitionTo($target))->toBe(in_array($target, $allowed, true));
        }
    })->with([
        'queued' => [AnalyticalReportStatus::Queued, [AnalyticalReportStatus::Generating, AnalyticalReportStatus::Failed, AnalyticalReportStatus::Queued]],
        'generating' => [AnalyticalReportStatus::Generating, [AnalyticalReportStatus::Generated, AnalyticalReportStatus::Failed, AnalyticalReportStatus::Queued]],
        'failed' => [AnalyticalReportStatus::Failed, [AnalyticalReportStatus::Queued]],
        'generated' => [AnalyticalReportStatus::Generated, []],
    ]);

    it('treats only queued and generating as in progress and only generated as terminal', function (): void {
        expect(array_map(fn (AnalyticalReportStatus $status): bool => $status->isInProgress(), AnalyticalReportStatus::cases()))
            ->toBe([true, true, false, false])
            ->and(array_map(fn (AnalyticalReportStatus $status): bool => $status->isTerminal(), AnalyticalReportStatus::cases()))
            ->toBe([false, false, true, false]);
    });
});

describe('PaymentDueSituation', function (): void {
    it('classifies a request against today', function (PaymentRequestStatus $status, string $dueDate, PaymentDueSituation $expected): void {
        $request = (new PaymentRequest)->forceFill(['status' => $status, 'due_date' => $dueDate]);

        expect(PaymentDueSituation::for($request, CarbonImmutable::parse('2026-09-15', 'America/Sao_Paulo')))->toBe($expected);
    })->with([
        'settled even when past due' => [PaymentRequestStatus::Settled, '2026-09-01', PaymentDueSituation::Paid],
        'due yesterday' => [PaymentRequestStatus::Launched, '2026-09-14', PaymentDueSituation::Overdue],
        'due today' => [PaymentRequestStatus::Requested, '2026-09-15', PaymentDueSituation::Upcoming],
        'due tomorrow' => [PaymentRequestStatus::Requested, '2026-09-16', PaymentDueSituation::Upcoming],
    ]);
});

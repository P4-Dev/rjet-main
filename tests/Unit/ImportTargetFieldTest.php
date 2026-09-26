<?php

declare(strict_types=1);

use App\Enums\ImportTargetField;
use Tests\TestCase;

uses(TestCase::class);

it('requires branch document when template has no branch', function (): void {
    $required = ImportTargetField::requiredForPublish(null, null);
    $values = array_map(fn (ImportTargetField $f): string => $f->value, $required);

    expect($values)->toContain('supplier_document')
        ->and($values)->toContain('cost_center_code')
        ->and($values)->toContain('gross_amount')
        ->and($values)->toContain('due_date')
        ->and($values)->toContain('branch_document');
});

it('does not require branch document when template has branch', function (): void {
    $required = ImportTargetField::requiredForPublish('branch-uuid', null);
    $values = array_map(fn (ImportTargetField $f): string => $f->value, $required);

    expect($values)->not->toContain('branch_document')
        ->and($values)->toContain('supplier_document');
});

it('returns labels for all target fields', function (ImportTargetField $field): void {
    expect($field->getLabel())->not->toBeEmpty();
})->with(ImportTargetField::cases());

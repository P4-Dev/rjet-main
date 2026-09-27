<?php

declare(strict_types=1);

use App\Exceptions\AttachmentException;

it('keeps internal identifiers out of batch user messages', function (Closure $makeException, string $internalDetail): void {
    $exception = $makeException($internalDetail);

    expect($exception->getMessage())->toContain($internalDetail)
        ->and($exception->getUserMessage())->toBeString()->not->toBeEmpty()->not->toContain($internalDetail);
})->with([
    'not classifiable status' => [fn (string $detail): AttachmentException => AttachmentException::batchNotClassifiable($detail), 'partially_failed'],
    'not retryable status' => [fn (string $detail): AttachmentException => AttachmentException::batchNotRetryable($detail), 'renamed'],
    'naming collision base' => [fn (string $detail): AttachmentException => AttachmentException::namingCollisionUnresolved($detail), '20260926_101530_001'],
    'storage move attachment id' => [fn (string $detail): AttachmentException => AttachmentException::storageMoveFailed($detail), '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'],
    'missing file path' => [fn (string $detail): AttachmentException => AttachmentException::fileNotFound($detail), 'attachments/attachment_batch/secret/file.pdf'],
]);

it('flags unauthorized batch operations with a 403 code', function (): void {
    expect(AttachmentException::unauthorizedBatchOperation()->getCode())->toBe(403);
});

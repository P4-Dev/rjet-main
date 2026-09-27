<?php

use App\Models\AttachmentBatch;
use App\Models\AttachmentBatchItem;
use App\Services\AttachmentBatchService;
use Database\Factories\AttachmentBatchFactory;
use Database\Factories\AttachmentBatchItemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Creates a batch with one item per entry; each entry may customize the item factory.
 * Physical files are written to the (faked) attachments disk.
 *
 * @param  list<(Closure(AttachmentBatchItemFactory): AttachmentBatchItemFactory)|null>  $itemStates
 */
function createAttachmentBatchWithItems(
    AttachmentBatchFactory $batchFactory,
    array $itemStates,
): AttachmentBatch {
    $batch = $batchFactory->create();

    foreach (array_values($itemStates) as $index => $state) {
        $factory = AttachmentBatchItem::factory()
            ->for($batch, 'batch')
            ->state(['sort_order' => $index]);

        $item = ($state !== null ? $state($factory) : $factory)->create();
        $attachment = $item->attachment;

        Storage::disk($attachment->disk)->put($attachment->path, 'file-'.$index);
    }

    app(AttachmentBatchService::class)->syncCounters($batch);

    return $batch->refresh();
}

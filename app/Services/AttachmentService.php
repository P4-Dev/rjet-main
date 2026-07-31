<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttachmentType;
use App\Enums\PaymentMethod;
use App\Exceptions\AttachmentException;
use App\Models\Attachment;
use App\Models\PaymentRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class AttachmentService
{
    /**
     * @throws AttachmentException
     */
    public function storeUploadedFile(
        Model $attachable,
        UploadedFile $file,
        ?AttachmentType $type = null,
        int $sortOrder = 0,
    ): Attachment {
        $mime = (string) ($file->getMimeType() ?? '');
        $size = (int) $file->getSize();

        $this->assertMimeAndSize($mime, $size);

        $disk = (string) config('rjet.attachments.disk');
        $directory = $this->directoryFor($attachable);
        $path = $file->store($directory, $disk);

        if ($path === false) {
            throw AttachmentException::fileNotFound($directory);
        }

        return Attachment::query()->create([
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'type' => $type,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size' => $size,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, string>  $originalNames
     * @return Collection<int, Attachment>
     *
     * @throws AttachmentException
     */
    public function storeManyFromPaths(
        Model $attachable,
        array $paths,
        array $originalNames = [],
        ?AttachmentType $defaultType = null,
    ): Collection {
        $disk = (string) config('rjet.attachments.disk');
        $attachments = new Collection;

        foreach (array_values($paths) as $index => $path) {
            ['mime' => $mime, 'size' => $size] = $this->assertStoredPathIsAllowed($disk, $path);
            $type = $defaultType;

            if (
                $type === null
                && $attachable instanceof PaymentRequest
                && $attachable->payment_method === PaymentMethod::Boleto
                && $mime === 'application/pdf'
            ) {
                $type = AttachmentType::Boleto;
            }

            $attachments->push(Attachment::query()->create([
                'attachable_type' => $attachable->getMorphClass(),
                'attachable_id' => $attachable->getKey(),
                'type' => $type ?? AttachmentType::Other,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $originalNames[$path] ?? basename($path),
                'mime_type' => $mime,
                'size' => $size,
                'sort_order' => $index,
            ]));
        }

        return $attachments;
    }

    public function delete(Attachment $attachment): void
    {
        $attachment->delete();
    }

    public function forceDelete(Attachment $attachment): void
    {
        $attachment->forceDelete();
    }

    public function directoryFor(Model $attachable): string
    {
        return config('rjet.attachments.directory').'/'.$attachable->getMorphClass().'/'.$attachable->getKey();
    }

    /**
     * @return array{mime: string, size: int}
     *
     * @throws AttachmentException
     */
    private function assertStoredPathIsAllowed(string $disk, string $path): array
    {
        if (! Storage::disk($disk)->exists($path)) {
            throw AttachmentException::fileNotFound($path);
        }

        $mime = (string) Storage::disk($disk)->mimeType($path);
        $size = (int) Storage::disk($disk)->size($path);

        $this->assertMimeAndSize($mime, $size);

        return ['mime' => $mime, 'size' => $size];
    }

    /**
     * @throws AttachmentException
     */
    private function assertMimeAndSize(string $mime, int $size): void
    {
        if (! in_array($mime, config('rjet.attachments.accepted_mime_types'), true)) {
            throw AttachmentException::invalidMimeType($mime);
        }

        $maxKilobytes = (int) config('rjet.attachments.max_kilobytes');

        if ($size > $maxKilobytes * 1024) {
            throw AttachmentException::fileTooLarge($maxKilobytes);
        }
    }
}

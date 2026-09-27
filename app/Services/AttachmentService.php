<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttachmentType;
use App\Enums\PaymentMethod;
use App\Exceptions\AttachmentException;
use App\Models\Attachment;
use App\Models\PaymentRequest;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class AttachmentService
{
    /**
     * @var array<string, string>
     */
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

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
        ?User $actor = null,
    ): Collection {
        $disk = (string) config('rjet.attachments.disk');
        $attachments = new Collection;

        foreach (array_values($paths) as $index => $path) {
            $submittedPath = $path;
            $path = $this->assertPathIsSafe($path, $attachable, $actor);
            ['mime' => $mime, 'size' => $size] = $this->assertStoredPathIsAllowed($disk, $path);
            $path = $this->relocateToAttachableDirectory($disk, $path, $attachable);
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
                'original_name' => $originalNames[$submittedPath] ?? $originalNames[$path] ?? basename($path),
                'mime_type' => $mime,
                'size' => $size,
                'sort_order' => $index,
            ]));
        }

        return $attachments;
    }

    /**
     * @throws AttachmentException
     */
    public function assertUploadedFileIsAllowed(UploadedFile $file): void
    {
        $this->assertMimeAndSize((string) ($file->getMimeType() ?? ''), (int) $file->getSize());
    }

    public function extensionFor(string $mime, string $originalName): string
    {
        if (isset(self::MIME_EXTENSIONS[$mime])) {
            return self::MIME_EXTENSIONS[$mime];
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return $extension !== '' ? $extension : 'bin';
    }

    public function isPathTaken(string $disk, string $path, ?string $ignoreAttachmentId = null): bool
    {
        if (Storage::disk($disk)->exists($path)) {
            return true;
        }

        return Attachment::withTrashed()
            ->where('disk', $disk)
            ->where('path', $path)
            ->when($ignoreAttachmentId !== null, fn ($query) => $query->whereKeyNot($ignoreAttachmentId))
            ->exists();
    }

    /**
     * Moves the physical file and persists the new path (plus extra attributes) on the same row.
     * $withinTransaction runs in the same DB transaction; the file is moved back when it fails.
     *
     * @param  array<string, mixed>  $attributes
     * @param  (Closure(Attachment): void)|null  $withinTransaction
     *
     * @throws AttachmentException
     */
    public function moveAndUpdatePath(
        Attachment $attachment,
        string $targetPath,
        array $attributes = [],
        ?Closure $withinTransaction = null,
    ): Attachment {
        $disk = Storage::disk($attachment->disk);
        $originalPath = $attachment->path;

        if ($originalPath !== $targetPath) {
            if (! $disk->exists($originalPath) || ! $disk->move($originalPath, $targetPath)) {
                throw AttachmentException::storageMoveFailed((string) $attachment->getKey());
            }
        }

        try {
            DB::transaction(function () use ($attachment, $attributes, $targetPath, $withinTransaction): void {
                $attachment->forceFill([...$attributes, 'path' => $targetPath])->save();

                if ($withinTransaction !== null) {
                    $withinTransaction($attachment);
                }
            });
        } catch (Throwable $e) {
            if ($originalPath !== $targetPath) {
                $disk->move($targetPath, $originalPath);
            }

            throw $e;
        }

        return $attachment;
    }

    public function syncAttachableFlags(?Model ...$attachables): void
    {
        foreach ($attachables as $attachable) {
            if ($attachable !== null && method_exists($attachable, 'syncHasAttachmentsFlag')) {
                $attachable->syncHasAttachmentsFlag();
            }
        }
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

    public function stagingDirectoryFor(?User $user): string
    {
        $userKey = $user?->getKey() ?? 'anonymous';

        return config('rjet.attachments.directory').'/staging/'.$userKey;
    }

    /**
     * Remove expired staging files and unreferenced files under the attachments root.
     *
     * @return array{staging_deleted: int, orphan_deleted: int}
     */
    public function cleanOrphans(bool $dryRun = false, ?int $stagingTtlHours = null): array
    {
        $disk = (string) config('rjet.attachments.disk');
        $root = (string) config('rjet.attachments.directory');
        $ttlHours = $stagingTtlHours ?? (int) config('rjet.attachments.staging_ttl_hours', 24);
        $cutoff = now()->subHours(max(0, $ttlHours))->getTimestamp();

        $stagingDeleted = 0;
        $orphanDeleted = 0;

        $referenced = Attachment::withTrashed()
            ->where('disk', $disk)
            ->pluck('path')
            ->flip();

        foreach (Storage::disk($disk)->allFiles($root) as $path) {
            $normalized = str_replace('\\', '/', $path);

            if (str_starts_with($normalized, $root.'/staging/')) {
                $lastModified = Storage::disk($disk)->lastModified($normalized);

                if ($lastModified <= $cutoff) {
                    if (! $dryRun) {
                        Storage::disk($disk)->delete($normalized);
                    }
                    $stagingDeleted++;
                }

                continue;
            }

            if ($referenced->has($normalized)) {
                continue;
            }

            if (! $dryRun) {
                Storage::disk($disk)->delete($normalized);
            }
            $orphanDeleted++;
        }

        return [
            'staging_deleted' => $stagingDeleted,
            'orphan_deleted' => $orphanDeleted,
        ];
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

        if (Attachment::withTrashed()->where('disk', $disk)->where('path', $path)->exists()) {
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
    private function assertPathIsSafe(string $path, Model $attachable, ?User $actor = null): string
    {
        $normalized = str_replace('\\', '/', $path);

        if (
            $normalized === ''
            || str_contains($normalized, '..')
            || str_starts_with($normalized, '/')
            || preg_match('/^[a-zA-Z]:/', $normalized) === 1
        ) {
            throw AttachmentException::fileNotFound($path);
        }

        $directory = (string) config('rjet.attachments.directory');

        if (! str_starts_with($normalized, $directory.'/')) {
            throw AttachmentException::fileNotFound($path);
        }

        $attachableDirectory = $this->directoryFor($attachable).'/';

        if (str_starts_with($normalized, $attachableDirectory)) {
            return $normalized;
        }

        $user = $actor ?? auth()->user();
        $stagingDirectory = $this->stagingDirectoryFor($user instanceof User ? $user : null).'/';

        if ($user instanceof User && str_starts_with($normalized, $stagingDirectory)) {
            return $normalized;
        }

        throw AttachmentException::fileNotFound($path);
    }

    /**
     * @throws AttachmentException
     */
    private function relocateToAttachableDirectory(string $disk, string $path, Model $attachable): string
    {
        $targetDirectory = $this->directoryFor($attachable);

        if (str_starts_with($path, $targetDirectory.'/')) {
            return $path;
        }

        $filename = basename($path);
        $targetPath = $targetDirectory.'/'.$filename;

        if (Storage::disk($disk)->exists($targetPath)) {
            $targetPath = $targetDirectory.'/'.uniqid('att_', true).'_'.$filename;
        }

        if (! Storage::disk($disk)->move($path, $targetPath)) {
            throw AttachmentException::fileNotFound($path);
        }

        return $targetPath;
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

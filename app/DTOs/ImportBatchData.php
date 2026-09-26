<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ImportBatchData
{
    /**
     * @param  list<array<string, mixed>>  $mappingsSnapshot
     */
    public function __construct(
        public string $importTemplateVersionId,
        public string $disk,
        public string $path,
        public string $originalFilename,
        public int $size,
        public array $mappingsSnapshot,
        public ?string $mimeType = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            importTemplateVersionId: (string) ($data['import_template_version_id'] ?? $data['importTemplateVersionId']),
            disk: (string) $data['disk'],
            path: (string) $data['path'],
            originalFilename: (string) ($data['original_filename'] ?? $data['originalFilename']),
            size: (int) $data['size'],
            mappingsSnapshot: (array) ($data['mappings_snapshot'] ?? $data['mappingsSnapshot'] ?? []),
            mimeType: isset($data['mime_type']) || isset($data['mimeType'])
                ? (($data['mime_type'] ?? $data['mimeType']) !== null
                    ? (string) ($data['mime_type'] ?? $data['mimeType'])
                    : null)
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'import_template_version_id' => $this->importTemplateVersionId,
            'disk' => $this->disk,
            'path' => $this->path,
            'original_filename' => $this->originalFilename,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'mappings_snapshot' => $this->mappingsSnapshot,
        ];
    }
}

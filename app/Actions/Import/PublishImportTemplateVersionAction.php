<?php

declare(strict_types=1);

namespace App\Actions\Import;

use App\DTOs\ImportTemplateMappingData;
use App\Exceptions\ImportException;
use App\Models\ImportTemplate;
use App\Models\ImportTemplateVersion;
use App\Models\User;
use App\Services\ImportTemplateService;

final class PublishImportTemplateVersionAction
{
    public function __construct(
        private readonly ImportTemplateService $importTemplateService,
    ) {}

    /**
     * @param  list<ImportTemplateMappingData|array<string, mixed>>  $mappings
     *
     * @throws ImportException
     */
    public function __invoke(ImportTemplate $template, array $mappings, User $actor): ImportTemplateVersion
    {
        $normalized = [];

        foreach ($mappings as $index => $mapping) {
            if ($mapping instanceof ImportTemplateMappingData) {
                $normalized[] = $mapping;

                continue;
            }

            $row = (array) $mapping;
            if (! isset($row['sort_order'])) {
                $row['sort_order'] = $index;
            }
            $normalized[] = ImportTemplateMappingData::fromArray($row);
        }

        return $this->importTemplateService->publishVersion($template, $normalized, $actor);
    }
}

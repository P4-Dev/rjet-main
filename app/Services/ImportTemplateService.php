<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ImportTemplateData;
use App\DTOs\ImportTemplateMappingData;
use App\Enums\ImportTargetField;
use App\Exceptions\ImportException;
use App\Models\ImportTemplate;
use App\Models\ImportTemplateMapping;
use App\Models\ImportTemplateVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ImportTemplateService
{
    public function create(ImportTemplateData $data, User $actor): ImportTemplate
    {
        $this->assertRequiredMappings($data->mappings, $data->branchId, $data->companyId);

        return DB::transaction(function () use ($data, $actor): ImportTemplate {
            /** @var ImportTemplate $template */
            $template = ImportTemplate::query()->create([
                'name' => $data->name,
                'company_id' => $data->companyId,
                'branch_id' => $data->branchId,
                'accepted_format' => $data->acceptedFormat,
                'is_active' => $data->isActive,
            ]);

            if ($template->created_by === null) {
                $template->forceFill([
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ])->saveQuietly();
            }

            $this->insertVersionWithMappings($template, 1, $data->mappings, $actor, isCurrent: true);

            return $template->fresh(['currentVersion.mappings', 'versions']) ?? $template;
        });
    }

    public function updateMetadata(ImportTemplate $template, ImportTemplateData $data, User $actor): ImportTemplate
    {
        $template->update([
            'name' => $data->name,
            'company_id' => $data->companyId,
            'branch_id' => $data->branchId,
            'accepted_format' => $data->acceptedFormat,
            'is_active' => $data->isActive,
            'updated_by' => $actor->getKey(),
        ]);

        return $template->fresh(['currentVersion']) ?? $template;
    }

    /**
     * @param  list<ImportTemplateMappingData>  $mappings
     */
    public function publishVersion(ImportTemplate $template, array $mappings, User $actor): ImportTemplateVersion
    {
        $this->assertRequiredMappings($mappings, $template->branch_id, $template->company_id);

        return DB::transaction(function () use ($template, $mappings, $actor): ImportTemplateVersion {
            ImportTemplateVersion::query()
                ->where('import_template_id', $template->getKey())
                ->where('is_current', true)
                ->whereNull('deleted_at')
                ->update(['is_current' => false]);

            $nextVersion = (int) ImportTemplateVersion::query()
                ->where('import_template_id', $template->getKey())
                ->max('version') + 1;

            return $this->insertVersionWithMappings($template, $nextVersion, $mappings, $actor, isCurrent: true);
        });
    }

    public function delete(ImportTemplate $template): void
    {
        $template->delete();
    }

    /**
     * @throws ImportException
     */
    public function softDeleteVersion(ImportTemplateVersion $version): void
    {
        if ($version->batches()->exists()) {
            throw ImportException::versionInUse();
        }

        $version->delete();
    }

    /**
     * @param  list<ImportTemplateMappingData>  $mappings
     *
     * @throws ImportException
     */
    public function assertRequiredMappings(array $mappings, ?string $branchId, ?string $companyId = null): void
    {
        $covered = [];

        foreach ($mappings as $mapping) {
            $hasSource = filled($mapping->sourceColumn);
            $hasDefault = filled($mapping->defaultValue);

            if ($hasSource || $hasDefault) {
                $covered[$mapping->targetField->value] = true;
            }
        }

        foreach (ImportTargetField::requiredForPublish($branchId, $companyId) as $required) {
            if (! isset($covered[$required->value])) {
                throw ImportException::templateMissingRequiredMappings();
            }
        }
    }

    /**
     * @param  list<ImportTemplateMappingData>  $mappings
     */
    private function insertVersionWithMappings(
        ImportTemplate $template,
        int $versionNumber,
        array $mappings,
        User $actor,
        bool $isCurrent,
    ): ImportTemplateVersion {
        $now = now();

        /** @var ImportTemplateVersion $version */
        $version = ImportTemplateVersion::query()->create([
            'import_template_id' => $template->getKey(),
            'version' => $versionNumber,
            'is_current' => $isCurrent,
            'published_at' => $now,
            'created_by' => $actor->getKey(),
            'created_at' => $now,
        ]);

        foreach ($mappings as $index => $mapping) {
            ImportTemplateMapping::query()->create([
                'import_template_version_id' => $version->getKey(),
                'source_column' => $mapping->sourceColumn,
                'target_field' => $mapping->targetField,
                'default_value' => $mapping->defaultValue,
                'sort_order' => $mapping->sortOrder > 0 ? $mapping->sortOrder : $index,
                'created_at' => $now,
            ]);
        }

        return $version->fresh(['mappings']) ?? $version;
    }
}

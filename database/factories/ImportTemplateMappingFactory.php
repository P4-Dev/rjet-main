<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportTargetField;
use App\Models\ImportTemplateMapping;
use App\Models\ImportTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportTemplateMapping>
 */
final class ImportTemplateMappingFactory extends Factory
{
    protected $model = ImportTemplateMapping::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'import_template_version_id' => ImportTemplateVersion::factory(),
            'source_column' => fake()->unique()->word(),
            'target_field' => ImportTargetField::SupplierDocument,
            'default_value' => null,
            'sort_order' => 0,
            'created_at' => now(),
        ];
    }

    public function forField(ImportTargetField $field): static
    {
        return $this->state(fn (): array => [
            'target_field' => $field,
            'source_column' => $field->value,
        ]);
    }

    public function defaultOnly(ImportTargetField $field, string $default): static
    {
        return $this->state(fn (): array => [
            'target_field' => $field,
            'source_column' => null,
            'default_value' => $default,
        ]);
    }
}

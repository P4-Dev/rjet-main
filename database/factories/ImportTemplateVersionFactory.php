<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ImportTemplate;
use App\Models\ImportTemplateVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportTemplateVersion>
 */
final class ImportTemplateVersionFactory extends Factory
{
    protected $model = ImportTemplateVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'import_template_id' => ImportTemplate::factory(),
            'version' => 1,
            'is_current' => true,
            'published_at' => now(),
            'created_by' => User::factory(),
            'created_at' => now(),
        ];
    }

    public function current(): static
    {
        return $this->state(fn (): array => ['is_current' => true]);
    }

    public function notCurrent(): static
    {
        return $this->state(fn (): array => ['is_current' => false]);
    }

    public function forTemplate(ImportTemplate $template): static
    {
        return $this->state(fn (): array => [
            'import_template_id' => $template->getKey(),
        ]);
    }
}

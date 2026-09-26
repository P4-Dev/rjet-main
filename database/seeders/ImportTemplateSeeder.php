<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\DTOs\ImportTemplateData;
use App\DTOs\ImportTemplateMappingData;
use App\Enums\ImportFileFormat;
use App\Enums\ImportTargetField;
use App\Models\Branch;
use App\Models\ImportTemplate;
use App\Models\User;
use App\Services\ImportTemplateService;
use Illuminate\Database\Seeder;

final class ImportTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (ImportTemplate::query()->where('name', 'Depósito Pix (CSV)')->exists()) {
            return;
        }

        $actor = User::query()->where('role', 'adm')->first()
            ?? User::factory()->adm()->create();

        $branch = Branch::query()->active()->first();

        app(ImportTemplateService::class)->create(
            new ImportTemplateData(
                name: 'Depósito Pix (CSV)',
                acceptedFormat: ImportFileFormat::Csv,
                isActive: true,
                companyId: $branch?->company_id,
                branchId: $branch?->getKey(),
                mappings: [
                    new ImportTemplateMappingData(ImportTargetField::SupplierDocument, 'fornecedor', null, 0),
                    new ImportTemplateMappingData(ImportTargetField::CostCenterCode, 'centro_custo', null, 1),
                    new ImportTemplateMappingData(ImportTargetField::GrossAmount, 'valor', null, 2),
                    new ImportTemplateMappingData(ImportTargetField::DueDate, 'vencimento', null, 3),
                    new ImportTemplateMappingData(ImportTargetField::PaymentMethod, null, 'deposit', 4),
                    new ImportTemplateMappingData(ImportTargetField::DepositType, null, 'pix', 5),
                    new ImportTemplateMappingData(ImportTargetField::PixKeyType, 'tipo_pix', null, 6),
                    new ImportTemplateMappingData(ImportTargetField::PixKey, 'chave_pix', null, 7),
                ],
            ),
            $actor,
        );
    }
}

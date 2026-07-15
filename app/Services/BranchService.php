<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BranchException;
use App\Models\Branch;

final class BranchService
{
    /**
     * Bloqueia o soft delete direto de filial que ainda possui contas bancárias.
     *
     * IMPORTANTE (DBA): este guard vive apenas na camada de Service — nunca como
     * evento Eloquent no Model Branch — para não bloquear a cascata de exclusão
     * de Company (que soft-deleta contas e filiais em lote).
     */
    public function delete(Branch $branch): void
    {
        $this->ensureDeletable($branch);

        $branch->delete();
    }

    /**
     * Valida se a filial pode ser excluída diretamente (guard de negócio).
     *
     * @throws BranchException
     */
    public function ensureDeletable(Branch $branch): void
    {
        if ($branch->bankAccounts()->exists()) {
            throw BranchException::cannotDeleteWithBankAccounts((string) $branch->getKey());
        }
    }
}

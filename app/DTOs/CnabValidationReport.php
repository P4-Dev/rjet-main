<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CnabValidationReport
{
    /**
     * @param  list<CnabValidationError>  $configErrors
     * @param  array<string, list<CnabValidationError>>  $itemErrors  keyed by settlement item id
     * @param  list<CnabValidationError>  $structuralErrors
     */
    public function __construct(
        public array $configErrors = [],
        public array $itemErrors = [],
        public array $structuralErrors = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errorCount() === 0;
    }

    public function errorCount(): int
    {
        $itemErrorCount = array_sum(array_map('count', $this->itemErrors));

        return count($this->configErrors) + $itemErrorCount + count($this->structuralErrors);
    }

    /**
     * @return list<string>
     */
    public function itemErrorCodes(): array
    {
        $codes = [];

        foreach ($this->itemErrors as $errors) {
            foreach ($errors as $error) {
                $codes[] = $error->code;
            }
        }

        return $codes;
    }
}

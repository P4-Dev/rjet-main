<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CnabValidationError
{
    /**
     * @param  array<string, int|string>  $params
     */
    public function __construct(
        public string $code,
        public ?string $field = null,
        public array $params = [],
        public ?string $settlementItemId = null,
    ) {}

    public function message(): string
    {
        return __('cnab_files.validation.'.$this->code, $this->params);
    }

    /**
     * @return array{code: string, field: ?string, params: array<string, int|string>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'field' => $this->field,
            'params' => $this->params,
        ];
    }
}

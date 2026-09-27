<?php

declare(strict_types=1);

namespace App\Integrations\Cnab;

use App\Enums\CnabLayout;
use App\Exceptions\CnabException;
use Illuminate\Contracts\Container\Container;

final class CnabAdapterResolver
{
    /**
     * @param  array<string, class-string<CnabRemittanceAdapter>>  $adapters  keyed by CnabLayout value
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $adapters,
    ) {}

    /**
     * @throws CnabException
     */
    public function for(CnabLayout $layout): CnabRemittanceAdapter
    {
        $adapterClass = $this->adapters[$layout->value] ?? null;

        if ($adapterClass === null) {
            throw CnabException::layoutNotSupported($layout->value);
        }

        /** @var CnabRemittanceAdapter $adapter */
        $adapter = $this->container->make($adapterClass);

        return $adapter;
    }

    public function supports(CnabLayout $layout): bool
    {
        return array_key_exists($layout->value, $this->adapters);
    }
}

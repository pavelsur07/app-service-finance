<?php

namespace App\Banking\Provider;

use App\Banking\Contract\BankProviderInterface;

final class ProviderRegistry
{
    /** @var array<string, BankProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<BankProviderInterface> $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $p) {
            $this->providers[$p->getCode()] = $p;
        }
    }

    public function get(string $code): BankProviderInterface
    {
        if (!isset($this->providers[$code])) {
            throw new \InvalidArgumentException("Unknown bank provider: $code");
        }

        return $this->providers[$code];
    }

    /**
     * @return string[] список доступных кодов провайдеров
     */
    public function list(): array
    {
        return array_keys($this->providers);
    }
}

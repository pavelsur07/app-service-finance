<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank;

use App\BankSync\Domain\BankProviderInterface;
use App\BankSync\Enum\BankCode;
use App\BankSync\Exception\UnknownBankCodeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Адаптеры банков по коду. Новый банк регистрируется классом, реализующим
 * `BankProviderInterface`, — правок здесь не нужно.
 */
final readonly class BankProviderRegistry
{
    /** @var array<string, BankProviderInterface> */
    private array $providers;

    /**
     * @param iterable<BankProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(BankProviderInterface::TAG)]
        iterable $providers,
    ) {
        $byCode = [];
        foreach ($providers as $provider) {
            $byCode[$provider->code()->value] = $provider;
        }
        $this->providers = $byCode;
    }

    public function get(BankCode $code): BankProviderInterface
    {
        return $this->providers[$code->value] ?? throw new UnknownBankCodeException($code->value);
    }
}

<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\OAuth;

use App\BankSync\Domain\OAuth\BankAuthorizationProviderInterface;
use App\BankSync\Enum\BankCode;
use App\BankSync\Exception\UnknownBankCodeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Провайдеры входа по коду банка. Банк без провайдера подключается только статическим
 * токеном.
 */
final readonly class BankAuthorizationProviderRegistry
{
    /** @var array<string, BankAuthorizationProviderInterface> */
    private array $providers;

    /**
     * @param iterable<BankAuthorizationProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(BankAuthorizationProviderInterface::TAG)]
        iterable $providers,
    ) {
        $byCode = [];
        foreach ($providers as $provider) {
            $byCode[$provider->code()->value] = $provider;
        }
        $this->providers = $byCode;
    }

    public function has(BankCode $code): bool
    {
        return isset($this->providers[$code->value]);
    }

    /**
     * @throws UnknownBankCodeException
     */
    public function get(BankCode $code): BankAuthorizationProviderInterface
    {
        return $this->providers[$code->value] ?? throw new UnknownBankCodeException($code->value);
    }
}

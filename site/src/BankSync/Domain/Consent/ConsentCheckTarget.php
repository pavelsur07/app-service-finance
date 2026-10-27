<?php

declare(strict_types=1);

namespace App\BankSync\Domain\Consent;

/**
 * Снимок подключения для ночной сверки согласий, снятый до запроса к банку: сущности не
 * держатся в EntityManager всю сверку. `credentialsEncrypted` — чтобы под блокировкой
 * отличить новый вход клиента, случившийся во время запроса, от прежней авторизации.
 */
final readonly class ConsentCheckTarget
{
    public function __construct(
        public string $connectionId,
        public string $companyId,
        public string $externalSubject,
        public ?string $consentExpiresOn,
        public string $credentialsEncrypted,
    ) {
    }

    /**
     * @return array{connectionId: string, consentExpiresOn: ?string}
     */
    public function __debugInfo(): array
    {
        return ['connectionId' => $this->connectionId, 'consentExpiresOn' => $this->consentExpiresOn];
    }
}

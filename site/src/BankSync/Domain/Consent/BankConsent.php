<?php

declare(strict_types=1);

namespace App\BankSync\Domain\Consent;

/**
 * Согласие пользователя банка по данным банка: чьё (`sub`), действует ли и до какого дня
 * включительно. Хэш организации и прочие поля ответа не хранятся.
 */
final readonly class BankConsent
{
    public function __construct(
        public string $subject,
        public bool $isActive,
        public \DateTimeImmutable $expiresOn,
    ) {
    }

    /**
     * @return array{isActive: bool, expiresOn: string}
     */
    public function __debugInfo(): array
    {
        return ['isActive' => $this->isActive, 'expiresOn' => $this->expiresOn->format('Y-m-d')];
    }
}

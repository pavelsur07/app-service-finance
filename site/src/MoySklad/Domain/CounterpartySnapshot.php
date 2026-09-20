<?php

declare(strict_types=1);

namespace App\MoySklad\Domain;

use Webmozart\Assert\Assert;

final readonly class CounterpartySnapshot
{
    public function __construct(
        public string $externalId,
        public string $name,
        public string $companyType,
        public ?string $legalTitle,
        public ?string $inn,
        public ?string $kpp,
        public ?string $ogrn,
        public ?string $ogrnip,
        public ?string $legalAddress,
        public bool $archived,
        public \DateTimeImmutable $sourceUpdatedAt,
    ) {
        Assert::uuid($externalId);
        Assert::notEq(trim($name), '');
        Assert::notEq(trim($companyType), '');
    }
}

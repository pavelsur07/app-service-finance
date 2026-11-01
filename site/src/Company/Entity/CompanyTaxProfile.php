<?php

declare(strict_types=1);

namespace App\Company\Entity;

use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;
use App\Company\Exception\InvalidCompanyTaxProfileException;
use App\Company\Repository\CompanyTaxProfileRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Налоговый профиль компании, действующий с 1-го числа месяца `validFrom`.
 * `validFrom = null` — начальный профиль («с начала учёта»): действует до первого датированного.
 * Уникальность (company_id, valid_from) NULLS NOT DISTINCT — в миграции Version20261101120000.
 */
#[ORM\Entity(repositoryClass: CompanyTaxProfileRepository::class)]
#[ORM\Table(name: 'company_tax_profiles')]
class CompanyTaxProfile
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    #[ORM\Column(name: 'tax_regime', length: 16, enumType: TaxRegime::class)]
    private TaxRegime $taxRegime;

    #[ORM\Column(name: 'vat_mode', length: 16, enumType: VatMode::class)]
    private VatMode $vatMode;

    #[ORM\Column(name: 'valid_from', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validFrom;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $companyId,
        TaxRegime $taxRegime,
        VatMode $vatMode,
        ?\DateTimeImmutable $validFrom,
        \DateTimeImmutable $now,
        ?string $id = null,
    ) {
        Assert::uuid($companyId);
        if (null !== $id) {
            Assert::uuid($id);
        }
        self::assertAllowed($taxRegime, $vatMode);

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->taxRegime = $taxRegime;
        $this->vatMode = $vatMode;
        $this->validFrom = self::normalizeValidFrom($validFrom);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Дата начала, приведённая к хранимому виду: календарная дата 1-го числа без времени.
     */
    public static function normalizeValidFrom(?\DateTimeImmutable $validFrom): ?\DateTimeImmutable
    {
        if (null === $validFrom) {
            return null;
        }
        if ('1' !== $validFrom->format('j')) {
            throw InvalidCompanyTaxProfileException::validFromNotFirstDay();
        }

        return new \DateTimeImmutable($validFrom->format('Y-m-d'));
    }

    public function change(TaxRegime $taxRegime, VatMode $vatMode, \DateTimeImmutable $now): void
    {
        self::assertAllowed($taxRegime, $vatMode);
        if ($taxRegime === $this->taxRegime && $vatMode === $this->vatMode) {
            return;
        }

        $this->taxRegime = $taxRegime;
        $this->vatMode = $vatMode;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getTaxRegime(): TaxRegime
    {
        return $this->taxRegime;
    }

    public function getVatMode(): VatMode
    {
        return $this->vatMode;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function assertAllowed(TaxRegime $taxRegime, VatMode $vatMode): void
    {
        if (!$taxRegime->allows($vatMode)) {
            throw InvalidCompanyTaxProfileException::vatModeNotAllowed($taxRegime->label(), $vatMode->label());
        }
    }
}

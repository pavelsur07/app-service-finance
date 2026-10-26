<?php

declare(strict_types=1);

namespace App\BankSync\Entity;

use App\BankSync\Enum\BankCode;
use App\BankSync\Repository\BankImportCursorRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Последний загруженный день выписки счёта. Сдвигается в одной транзакции со строками дня:
 * курсор не обгоняет данные.
 */
#[ORM\Entity(repositoryClass: BankImportCursorRepository::class)]
#[ORM\Table(name: 'bank_sync_import_cursor')]
#[ORM\UniqueConstraint(name: 'uniq_bank_sync_import_cursor_account', columns: ['company_id', 'bank_code', 'account_number'])]
class BankImportCursor
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(length: 32, enumType: BankCode::class)]
    private BankCode $bankCode;

    #[ORM\Column(length: 64)]
    private string $accountNumber;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastImportedDate = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $companyId, BankCode $bankCode, string $accountNumber, ?string $id = null)
    {
        if (null !== $id) {
            Assert::uuid($id);
        }
        Assert::uuid($companyId);
        Assert::stringNotEmpty($accountNumber);
        Assert::maxLength($accountNumber, 64);

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->bankCode = $bankCode;
        $this->accountNumber = $accountNumber;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Первый день следующей загрузки: накануне последнего загруженного (банк может дописать
     * операции задним числом), при первой загрузке — `$firstRunDays` дней назад.
     */
    public function nextStartDate(\DateTimeImmutable $today, int $firstRunDays): \DateTimeImmutable
    {
        return null !== $this->lastImportedDate
            ? $this->lastImportedDate->modify('-1 day')
            : $today->modify(sprintf('-%d days', $firstRunDays));
    }

    public function markImported(\DateTimeImmutable $date): void
    {
        $this->lastImportedDate = $date->setTime(0, 0);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getAccountNumber(): string
    {
        return $this->accountNumber;
    }

    public function getLastImportedDate(): ?\DateTimeImmutable
    {
        return $this->lastImportedDate;
    }
}

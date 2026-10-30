<?php

declare(strict_types=1);

namespace App\Cash\Entity\Transaction;

use App\Cash\Repository\Transaction\CashCategorizationTaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Поставленная задача категоризации: ключ повтора и исходный payload.
 *
 * Пишется в одной транзакции со строками подсказок до отправки. Сбой между commit и
 * отправкой, потеря сообщения или недоступный сервис чинятся переотправкой ровно этого
 * payload с тем же ключом: сервис отвечает той же задачей (другой payload под тем же
 * ключом он отверг бы как конфликт). Удаляется, когда у задачи не осталось строк
 * `requested`.
 */
#[ORM\Entity(repositoryClass: CashCategorizationTaskRepository::class)]
#[ORM\Table(name: 'cash_categorization_task')]
#[ORM\Index(name: 'idx_cash_categorization_task_company_dispatched', columns: ['company_id', 'dispatched_at'])]
class CashCategorizationTask
{
    /** task_id, выведенный ServiceGateway из компании, типа задачи и ключа. */
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(length: 200)]
    private string $requestKey;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dispatchedAt;

    #[ORM\Column(type: Types::INTEGER)]
    private int $dispatchCount = 1;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $id, string $companyId, string $requestKey, array $payload, \DateTimeImmutable $createdAt)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::stringNotEmpty($requestKey);
        Assert::maxLength($requestKey, 200);

        $this->id = $id;
        $this->companyId = $companyId;
        $this->requestKey = $requestKey;
        $this->payload = $payload;
        $this->createdAt = $createdAt;
        $this->dispatchedAt = $createdAt;
    }

    public function markDispatched(\DateTimeImmutable $at): void
    {
        $this->dispatchedAt = $at;
        ++$this->dispatchCount;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getRequestKey(): string
    {
        return $this->requestKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getDispatchedAt(): \DateTimeImmutable
    {
        return $this->dispatchedAt;
    }

    public function getDispatchCount(): int
    {
        return $this->dispatchCount;
    }
}

<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Accounts\MoneyFundMovement;
use App\Cash\Repository\Accounts\MoneyFundMovementRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Сохраняет движение по фонду.
 *
 * ID движения — ключ повтора формы: движение своей компании с этим ID уже есть — оно и
 * результат, второе не создаётся. Гонку двух одновременных отправок останавливает PK.
 */
final class CreateMoneyFundMovementAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MoneyFundMovementRepository $movementRepository,
        private readonly ManagerRegistry $managerRegistry,
    ) {
    }

    public function createDraft(\App\Cash\Entity\Accounts\MoneyFund $fund, string $id): MoneyFundMovement
    {
        return new MoneyFundMovement($id, $fund->getCompany(), $fund, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    public function setAmount(MoneyFundMovement $movement, string $input): void
    {
        $input = str_replace(["\u{202F}", "\u{2009}"], ' ', $input);
        $movement->setAmountMinor(\App\Shared\Domain\ValueObject\Money::fromString('' === trim($input) ? '0' : $input, $movement->getFund()->getCurrency())->amountMinor());
    }

    public function __invoke(MoneyFundMovement $movement, ?string $actorId = null): MoneyFundMovement
    {
        $id = (string) $movement->getId();
        $companyId = (string) $movement->getCompany()->getId();

        $repeated = $this->movementRepository->findOneByIdAndCompanyId($id, $companyId);
        if ($repeated instanceof MoneyFundMovement) {
            return $repeated;
        }

        try {
            if (null !== $actorId) {
                $movement->setUserId($actorId);
            }
            $this->entityManager->persist($movement);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            return $this->concurrentlyCreated($id, $companyId, $exception);
        }

        return $movement;
    }

    /**
     * Движение, созданное параллельной отправкой той же формы. EntityManager после ошибки flush
     * закрыт — читаем свежим. Ключ чужой компании или другой unique — не повтор.
     */
    private function concurrentlyCreated(string $id, string $companyId, UniqueConstraintViolationException $exception): MoneyFundMovement
    {
        $ownerCompanyId = $this->entityManager->getConnection()->fetchOne(
            'SELECT company_id FROM money_fund_movement WHERE id = :id',
            ['id' => $id],
        );
        if (false === $ownerCompanyId || (string) $ownerCompanyId !== $companyId) {
            throw $exception;
        }

        $this->managerRegistry->resetManager();
        $entityManager = $this->managerRegistry->getManager();
        \assert($entityManager instanceof EntityManagerInterface);

        $movement = $entityManager->getRepository(MoneyFundMovement::class)->findOneBy(['id' => $id, 'company' => $companyId]);
        if (!$movement instanceof MoneyFundMovement) {
            throw $exception;
        }

        return $movement;
    }
}

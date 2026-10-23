<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Domain\Identifier\CanonicalUuid;
use App\Cash\DTO\PaymentPlanDTO;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Repository\PaymentPlan\PaymentPlanRepository;
use App\Cash\Service\PaymentPlan\PaymentPlanDtoApplier;
use App\Company\Entity\Company;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Создаёт плановый платёж из формы платёжного календаря.
 *
 * `PaymentPlanDTO::$idempotencyKey` — ID плана: план своей компании с этим ID уже есть — он и
 * результат, второй не создаётся. Гонку двух одновременных отправок останавливает PK.
 */
final class CreatePaymentPlanAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentPlanDtoApplier $dtoApplier,
        private readonly PaymentPlanRepository $planRepository,
        private readonly ManagerRegistry $managerRegistry,
    ) {
    }

    public function __invoke(Company $company, PaymentPlanDTO $dto): PaymentPlan
    {
        $category = $dto->cashflowCategory;
        Assert::notNull($category, 'Статья ДДС планового платежа обязательна.');

        $companyId = (string) $company->getId();
        $key = null !== $dto->idempotencyKey && CanonicalUuid::isValid($dto->idempotencyKey) ? $dto->idempotencyKey : null;

        if (null !== $key) {
            $repeated = $this->planRepository->findOneByIdAndCompanyId($key, $companyId);
            if ($repeated instanceof PaymentPlan) {
                return $repeated;
            }
        }

        $plan = new PaymentPlan(
            $key ?? Uuid::uuid4()->toString(),
            $company,
            $category,
            \DateTimeImmutable::createFromInterface($dto->plannedAt),
            (string) $dto->amount
        );

        $this->dtoApplier->apply($plan, $company, $dto);

        try {
            $this->entityManager->persist($plan);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            if (null === $key) {
                throw $exception;
            }

            return $this->concurrentlyCreated($key, $companyId, $exception);
        }

        return $plan;
    }

    /**
     * План, созданный параллельной отправкой той же формы. EntityManager после ошибки flush
     * закрыт — читаем свежим. Ключ чужой компании или другой unique — не повтор.
     */
    private function concurrentlyCreated(string $key, string $companyId, UniqueConstraintViolationException $exception): PaymentPlan
    {
        $ownerCompanyId = $this->entityManager->getConnection()->fetchOne(
            'SELECT company_id FROM payment_plan WHERE id = :id',
            ['id' => $key],
        );
        if (false === $ownerCompanyId || (string) $ownerCompanyId !== $companyId) {
            throw $exception;
        }

        $this->managerRegistry->resetManager();
        $entityManager = $this->managerRegistry->getManager();
        \assert($entityManager instanceof EntityManagerInterface);

        $plan = $entityManager->getRepository(PaymentPlan::class)->findOneBy(['id' => $key, 'company' => $companyId]);
        if (!$plan instanceof PaymentPlan) {
            throw $exception;
        }

        return $plan;
    }
}

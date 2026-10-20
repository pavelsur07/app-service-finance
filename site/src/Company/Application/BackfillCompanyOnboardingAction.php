<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Application\DTO\CompanyOnboardingBackfillResult;
use App\Company\Event\CompanyOnboardingBackfillEvent;
use App\Company\Infrastructure\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Webmozart\Assert\Assert;

/**
 * Дозаполняет стартовые данные компаний, созданных до онбординга. Что и как заводить,
 * решают модули-подписчики `CompanyOnboardingBackfillEvent`; здесь — обход компаний,
 * сверка с dry-run и граница транзакции. Без $execute ничего не пишет.
 */
final readonly class BackfillCompanyOnboardingAction
{
    public function __construct(
        private CompanyRepository $companies,
        private EventDispatcherInterface $dispatcher,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string|null $companyId одна компания или все
     *
     * @throws \InvalidArgumentException неизвестная компания; --execute без сверки с dry-run или данные разошлись
     */
    public function __invoke(?string $companyId, bool $execute, ?int $expectedCount): CompanyOnboardingBackfillResult
    {
        if (null !== $companyId) {
            Assert::uuid($companyId);
            if (null === $this->companies->findById($companyId)) {
                throw new \InvalidArgumentException(sprintf('Компания %s не найдена.', $companyId));
            }
        }
        $companyIds = null === $companyId ? $this->companies->getAllActiveCompanyIds() : [$companyId];

        $result = new CompanyOnboardingBackfillResult();
        $result->companies = \count($companyIds);
        foreach ($companyIds as $id) {
            $event = new CompanyOnboardingBackfillEvent($id, execute: false);
            $this->dispatcher->dispatch($event);
            if ([] !== $event->gaps()) {
                $result->candidates[$id] = $event->gaps();
                foreach ($event->gaps() as $item => $count) {
                    $result->totals[$item] = ($result->totals[$item] ?? 0) + $count;
                }
            }
            // Подписчики грузят сущности компании; на всём списке это копится в памяти.
            $this->entityManager->clear();
        }

        ksort($result->totals);

        if (!$execute) {
            return $result;
        }
        if (null === $expectedCount) {
            throw new \InvalidArgumentException('Для --execute нужен --expected-count из последнего dry-run.');
        }
        if (\count($result->candidates) !== $expectedCount) {
            throw new \InvalidArgumentException(sprintf('Компаний с пробелами %d, ожидалось %d: данные изменились, повторите dry-run.', \count($result->candidates), $expectedCount));
        }

        $this->logger->info('Company onboarding backfill started', ['candidates' => \count($result->candidates), 'totals' => $result->totals]);
        foreach (array_keys($result->candidates) as $id) {
            try {
                // Компания — своя транзакция: сбой одного модуля не оставляет её дозаполненной наполовину.
                $this->entityManager->wrapInTransaction(function () use ($id): void {
                    $this->dispatcher->dispatch(new CompanyOnboardingBackfillEvent($id, execute: true));
                });
                ++$result->completed;
                $this->entityManager->clear();
            } catch (\Throwable $e) {
                // Останов на первой ошибке: EntityManager после сбоя закрыт; повторный запуск продолжит с оставшихся.
                $result->failed = 1;
                $this->logger->error('Company onboarding backfill stopped on error', [
                    'companyId' => $id,
                    'completed' => $result->completed,
                    'remaining' => \count($result->candidates) - $result->completed,
                    'exception' => $e,
                ]);
                break;
            }
        }
        $this->logger->info('Company onboarding backfill finished', ['completed' => $result->completed, 'failed' => $result->failed]);

        return $result;
    }
}

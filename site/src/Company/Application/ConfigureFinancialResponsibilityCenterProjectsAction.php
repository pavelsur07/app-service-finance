<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Repository\FinancialResponsibilityCenterProjectRepository;
use App\Company\Repository\FinancialResponsibilityCenterRepository;
use App\Company\Repository\ProjectDirectionRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Webmozart\Assert\Assert;

final readonly class ConfigureFinancialResponsibilityCenterProjectsAction
{
    public function __construct(
        private FinancialResponsibilityCenterRepository $centerRepository,
        private FinancialResponsibilityCenterProjectRepository $pairRepository,
        private ProjectDirectionRepository $projectRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<string> $projectDirectionIds
     */
    public function __invoke(
        string $companyId,
        string $responsibilityCenterId,
        int $expectedVersion,
        array $projectDirectionIds,
    ): void {
        $projectDirectionIds = \array_values(\array_unique($projectDirectionIds));
        foreach ($projectDirectionIds as $projectDirectionId) {
            Assert::uuid($projectDirectionId);
        }

        try {
            $center = $this->centerRepository->findOneByIdAndCompanyId($responsibilityCenterId, $companyId)
                ?? throw new \DomainException('ЦФО не найден.');
            $this->entityManager->lock($center, LockMode::OPTIMISTIC, $expectedVersion);

            $projects = $this->projectRepository->findByIdsAndCompanyId($projectDirectionIds, $companyId);
            if (\count($projects) !== \count($projectDirectionIds)) {
                throw new \DomainException('Один или несколько проектов не найдены в активной компании.');
            }

            $existingPairs = $this->pairRepository->findByCenterIdAndCompanyId($responsibilityCenterId, $companyId);
            $existingByProjectId = [];
            foreach ($existingPairs as $pair) {
                $existingByProjectId[(string) $pair->getProjectDirection()->getId()] = $pair;
            }

            if ($center->isSystem()) {
                $systemProjectId = null;
                foreach ($existingPairs as $pair) {
                    if (ProjectDirection::CODE_GENERAL === $pair->getProjectDirection()->getSystemCode()) {
                        $systemProjectId = (string) $pair->getProjectDirection()->getId();
                        break;
                    }
                }

                if (null === $systemProjectId || !\in_array($systemProjectId, $projectDirectionIds, true)) {
                    throw new \DomainException('Системную пару проекта и ЦФО нельзя удалить.');
                }
            }

            $projectsById = [];
            foreach ($projects as $project) {
                $projectsById[(string) $project->getId()] = $project;
            }

            $changed = [] !== \array_diff_key($existingByProjectId, $projectsById)
                || [] !== \array_diff_key($projectsById, $existingByProjectId);
            if (!$changed) {
                return;
            }

            $this->entityManager->wrapInTransaction(function () use (
                $center,
                $companyId,
                $expectedVersion,
                $existingByProjectId,
                $projectsById,
            ): void {
                $this->entityManager->lock($center, LockMode::OPTIMISTIC, $expectedVersion);

                $center->markProjectConfigurationChanged();
                $this->entityManager->flush();

                foreach (\array_diff_key($existingByProjectId, $projectsById) as $pair) {
                    $this->entityManager->remove($pair);
                }
                foreach (\array_diff_key($projectsById, $existingByProjectId) as $project) {
                    $this->entityManager->persist(new FinancialResponsibilityCenterProject(
                        $companyId,
                        $project,
                        $center,
                    ));
                }

                $this->entityManager->flush();
            });
        } catch (OptimisticLockException $exception) {
            throw new \DomainException('ЦФО был изменён другим пользователем. Обновите страницу.', previous: $exception);
        }
    }
}

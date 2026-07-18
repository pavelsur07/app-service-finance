<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Repository\ProjectDirectionRepository;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;

final readonly class FinanceDocumentResponsibilityCenterNormalizer
{
    public function __construct(
        private FinanceResponsibilityCenterPairValidator $pairValidator,
        private FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
        private ProjectDirectionRepository $projectDirectionRepository,
    ) {
    }

    /**
     * @return array{projectDirectionId: ?string, responsibilityCenterId: ?string}
     */
    public function snapshotDocument(Document $document): array
    {
        return $this->snapshot($document->getProjectDirection(), $document->getResponsibilityCenterId());
    }

    /**
     * @return array<string, array{projectDirectionId: ?string, responsibilityCenterId: ?string}>
     */
    public function snapshotOperations(Document $document): array
    {
        $snapshots = [];
        foreach ($document->getOperations() as $operation) {
            $snapshots[(string) $operation->getId()] = $this->snapshot(
                $operation->getProjectDirection(),
                $operation->getResponsibilityCenterId(),
            );
        }

        return $snapshots;
    }

    public function prepareNewManualDocument(Document $document, Company $company): void
    {
        $this->prepareManualDocument($document, $company, false, null, []);
    }

    /**
     * @param array{projectDirectionId: ?string, responsibilityCenterId: ?string} $documentSnapshot
     * @param array<string, array{projectDirectionId: ?string, responsibilityCenterId: ?string}> $operationSnapshots
     */
    public function prepareExistingManualDocument(
        Document $document,
        Company $company,
        array $documentSnapshot,
        array $operationSnapshots,
    ): void {
        $this->prepareManualDocument($document, $company, true, $documentSnapshot, $operationSnapshots);
    }

    public function prepareExplicitDocument(Document $document, Company $company): void
    {
        foreach ($document->getOperations() as $operation) {
            $this->inheritFromDocument($operation, $document);
        }

        $this->validateExplicitPair(
            $company,
            $document->getProjectDirection(),
            $document->getResponsibilityCenterId(),
        );

        foreach ($document->getOperations() as $operation) {
            $this->validateExplicitPair(
                $company,
                $operation->getProjectDirection(),
                $operation->getResponsibilityCenterId(),
            );
        }
    }

    /**
     * @param array{projectDirectionId: ?string, responsibilityCenterId: ?string}|null $documentSnapshot
     * @param array<string, array{projectDirectionId: ?string, responsibilityCenterId: ?string}> $operationSnapshots
     */
    private function prepareManualDocument(
        Document $document,
        Company $company,
        bool $allowUnchangedLegacy,
        ?array $documentSnapshot,
        array $operationSnapshots,
    ): void {
        $this->applySystemDefaultsToDocument($document, $company, $allowUnchangedLegacy, $documentSnapshot);
        $this->validateManualPair(
            $company,
            $document->getProjectDirection(),
            $document->getResponsibilityCenterId(),
            $allowUnchangedLegacy,
            $documentSnapshot,
        );

        foreach ($document->getOperations() as $operation) {
            $this->inheritFromDocument($operation, $document);
            $operationSnapshot = $operationSnapshots[(string) $operation->getId()] ?? null;
            $this->applySystemDefaultsToOperation($operation, $company, $allowUnchangedLegacy, $operationSnapshot);
            $this->validateManualPair(
                $company,
                $operation->getProjectDirection(),
                $operation->getResponsibilityCenterId(),
                $allowUnchangedLegacy,
                $operationSnapshot,
            );
        }
    }

    /**
     * @param array{projectDirectionId: ?string, responsibilityCenterId: ?string}|null $snapshot
     */
    private function applySystemDefaultsToDocument(
        Document $document,
        Company $company,
        bool $allowUnchangedLegacy,
        ?array $snapshot,
    ): void {
        if ($this->isUnchangedIncompletePair($document->getProjectDirection(), $document->getResponsibilityCenterId(), $allowUnchangedLegacy, $snapshot)) {
            return;
        }

        [$project, $responsibilityCenterId] = $this->withSystemDefaults(
            $company,
            $document->getProjectDirection(),
            $document->getResponsibilityCenterId(),
        );
        $document
            ->setProjectDirection($project)
            ->setResponsibilityCenterId($responsibilityCenterId);
    }

    /**
     * @param array{projectDirectionId: ?string, responsibilityCenterId: ?string}|null $snapshot
     */
    private function applySystemDefaultsToOperation(
        DocumentOperation $operation,
        Company $company,
        bool $allowUnchangedLegacy,
        ?array $snapshot,
    ): void {
        if ($this->isUnchangedIncompletePair($operation->getProjectDirection(), $operation->getResponsibilityCenterId(), $allowUnchangedLegacy, $snapshot)) {
            return;
        }

        [$project, $responsibilityCenterId] = $this->withSystemDefaults(
            $company,
            $operation->getProjectDirection(),
            $operation->getResponsibilityCenterId(),
        );
        $operation
            ->setProjectDirection($project)
            ->setResponsibilityCenterId($responsibilityCenterId);
    }

    private function inheritFromDocument(DocumentOperation $operation, Document $document): void
    {
        if (null === $operation->getProjectDirection() && null !== $document->getProjectDirection()) {
            $operation->setProjectDirection($document->getProjectDirection());
        }

        if (null === $operation->getResponsibilityCenterId() && null !== $document->getResponsibilityCenterId()) {
            $operation->setResponsibilityCenterId($document->getResponsibilityCenterId());
        }
    }

    /**
     * @return array{0: ?ProjectDirection, 1: ?string}
     */
    private function withSystemDefaults(
        Company $company,
        ?ProjectDirection $project,
        ?string $responsibilityCenterId,
    ): array {
        $systemPair = $this->responsibilityCenterFacade->findGeneralPair((string) $company->getId());
        if (null === $systemPair) {
            throw new \DomainException('Системная пара PROJECT_GENERAL × CFO_GENERAL не найдена.');
        }

        if (null === $project && null === $responsibilityCenterId) {
            return [$this->findSystemProject($company, $systemPair->projectDirectionId), $systemPair->responsibilityCenterId];
        }

        if ($project?->isSystem() && null === $responsibilityCenterId) {
            return [$project, $systemPair->responsibilityCenterId];
        }

        if (null === $project && $responsibilityCenterId === $systemPair->responsibilityCenterId) {
            return [$this->findSystemProject($company, $systemPair->projectDirectionId), $responsibilityCenterId];
        }

        return [$project, $responsibilityCenterId];
    }

    private function findSystemProject(Company $company, string $projectDirectionId): ProjectDirection
    {
        $project = $this->projectDirectionRepository->find($projectDirectionId);
        if (!$project instanceof ProjectDirection || $project->getCompany()->getId() !== $company->getId()) {
            throw new \DomainException('Системный проект PROJECT_GENERAL не найден.');
        }

        return $project;
    }

    /**
     * @param array{projectDirectionId: ?string, responsibilityCenterId: ?string}|null $snapshot
     */
    private function validateManualPair(
        Company $company,
        ?ProjectDirection $project,
        ?string $responsibilityCenterId,
        bool $allowUnchangedLegacy,
        ?array $snapshot,
    ): void {
        if ($this->isUnchangedPair($project, $responsibilityCenterId, $allowUnchangedLegacy, $snapshot)) {
            return;
        }

        if (null !== $project && null === $responsibilityCenterId) {
            throw new \DomainException('Укажите ЦФО для проекта.');
        }

        $this->pairValidator->assertValidNullablePair(
            (string) $company->getId(),
            null === $project ? null : (string) $project->getId(),
            $responsibilityCenterId,
        );
    }

    private function validateExplicitPair(
        Company $company,
        ?ProjectDirection $project,
        ?string $responsibilityCenterId,
    ): void {
        if (null === $responsibilityCenterId) {
            return;
        }

        $this->pairValidator->assertValidNullablePair(
            (string) $company->getId(),
            null === $project ? null : (string) $project->getId(),
            $responsibilityCenterId,
        );
    }

    /**
     * @param array{projectDirectionId: ?string, responsibilityCenterId: ?string}|null $snapshot
     */
    private function isUnchangedIncompletePair(
        ?ProjectDirection $project,
        ?string $responsibilityCenterId,
        bool $allowUnchangedLegacy,
        ?array $snapshot,
    ): bool {
        return (null === $project || null === $responsibilityCenterId)
            && $this->isUnchangedPair($project, $responsibilityCenterId, $allowUnchangedLegacy, $snapshot);
    }

    /**
     * @param array{projectDirectionId: ?string, responsibilityCenterId: ?string}|null $snapshot
     */
    private function isUnchangedPair(
        ?ProjectDirection $project,
        ?string $responsibilityCenterId,
        bool $allowUnchangedLegacy,
        ?array $snapshot,
    ): bool {
        if (!$allowUnchangedLegacy || null === $snapshot) {
            return false;
        }

        return $snapshot === $this->snapshot($project, $responsibilityCenterId);
    }

    /**
     * @return array{projectDirectionId: ?string, responsibilityCenterId: ?string}
     */
    private function snapshot(?ProjectDirection $project, ?string $responsibilityCenterId): array
    {
        return [
            'projectDirectionId' => null === $project ? null : (string) $project->getId(),
            'responsibilityCenterId' => $responsibilityCenterId,
        ];
    }
}

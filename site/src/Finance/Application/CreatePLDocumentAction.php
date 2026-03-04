<?php

declare(strict_types=1);

namespace App\Finance\Application;

use App\Company\Entity\Company;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Entity\Counterparty;
use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Entity\PLCategory;
use App\Entity\ProjectDirection;
use App\Finance\Application\Command\CreatePLDocumentCommand;
use App\Finance\Application\Command\CreatePLDocumentOperationCommand;
use App\Repository\CounterpartyRepository;
use App\Repository\DocumentRepository;
use App\Repository\PLCategoryRepository;
use App\Repository\ProjectDirectionRepository;
use App\Service\PLRegisterUpdater;
use Ramsey\Uuid\Uuid;

final readonly class CreatePLDocumentAction
{
    public function __construct(
        private CompanyRepository $companyRepository,
        private DocumentRepository $documentRepository,
        private PLCategoryRepository $plCategoryRepository,
        private CounterpartyRepository $counterpartyRepository,
        private ProjectDirectionRepository $projectDirectionRepository,
        private PLRegisterUpdater $plRegisterUpdater,
    ) {
    }

    public function __invoke(CreatePLDocumentCommand $command): string
    {
        if ([] === $command->operations) {
            throw new \DomainException('Документ ОПиУ должен содержать хотя бы одну операцию.');
        }

        $company = $this->companyRepository->findById($command->companyId);
        if (!$company instanceof Company) {
            throw new \DomainException('Компания не найдена.');
        }

        $document = new Document(Uuid::uuid7()->toString(), $company);
        $document
            ->setDate($command->date)
            ->setType($command->type)
            ->setStatus($command->status)
            ->setNumber($command->number)
            ->setDescription($command->description)
            ->setCounterparty($this->resolveCounterparty($command->counterpartyId, $company))
            ->setProjectDirection($this->resolveProjectDirection($command->projectDirectionId, $company));

        foreach ($command->operations as $operationCommand) {
            $operation = $this->createOperation($operationCommand, $company);
            $document->addOperation($operation);
        }

        $this->documentRepository->save($document);
        $this->plRegisterUpdater->updateForDocument($document);

        return (string) $document->getId();
    }

    private function createOperation(CreatePLDocumentOperationCommand $command, Company $company): DocumentOperation
    {
        return (new DocumentOperation(Uuid::uuid7()->toString()))
            ->setAmount($command->amount)
            ->setPlCategory($this->resolveCategory($command->categoryId, $company))
            ->setCounterparty($this->resolveCounterparty($command->counterpartyId, $company))
            ->setProjectDirection($this->resolveProjectDirection($command->projectDirectionId, $company))
            ->setComment($command->comment);
    }

    private function resolveCategory(?string $id, Company $company): ?PLCategory
    {
        if (null === $id || '' === $id) {
            return null;
        }

        $category = $this->plCategoryRepository->find($id);
        if (!$category instanceof PLCategory || $category->getCompany()->getId() !== $company->getId()) {
            throw new \DomainException('Категория ОПиУ не найдена.');
        }

        return $category;
    }

    private function resolveCounterparty(?string $id, Company $company): ?Counterparty
    {
        if (null === $id || '' === $id) {
            return null;
        }

        $counterparty = $this->counterpartyRepository->find($id);
        if (!$counterparty instanceof Counterparty || $counterparty->getCompany()->getId() !== $company->getId()) {
            throw new \DomainException('Контрагент не найден.');
        }

        return $counterparty;
    }

    private function resolveProjectDirection(?string $id, Company $company): ?ProjectDirection
    {
        if (null === $id || '' === $id) {
            return null;
        }

        $projectDirection = $this->projectDirectionRepository->find($id);
        if (!$projectDirection instanceof ProjectDirection || $projectDirection->getCompany()->getId() !== $company->getId()) {
            throw new \DomainException('Проект не найден.');
        }

        return $projectDirection;
    }
}

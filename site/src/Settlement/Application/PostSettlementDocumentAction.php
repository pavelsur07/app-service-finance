<?php

declare(strict_types=1);

namespace App\Settlement\Application;

use App\Finance\Facade\FinanceFacade;
use App\Settlement\Application\DTO\SettlementResult;
use App\Settlement\Application\Service\SettlementRegisterUpdater;
use App\Settlement\Domain\SettlementFinanceSourcePolicy;
use App\Settlement\Domain\SettlementMutationPolicy;
use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Entity\SettlementItem;
use App\Settlement\Entity\SettlementSourceLink;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Enum\SettlementDocumentStatus;
use App\Settlement\Enum\SettlementSourceType;
use App\Settlement\Repository\SettlementRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PostSettlementDocumentAction
{
    public function __construct(private EntityManagerInterface $em, private SettlementMutationPolicy $policy, private SettlementRepository $repository, private SettlementRegisterUpdater $updater, private FinanceFacade $finance, private SettlementFinanceSourcePolicy $sourcePolicy, private \App\Settlement\Domain\SettlementPostingPolicy $posting)
    {
    }

    public function __invoke(string $companyId, string $actorUserId, string $documentId, int $expectedVersion): SettlementResult
    {
        return $this->em->wrapInTransaction(function () use ($companyId, $actorUserId, $documentId, $expectedVersion): SettlementResult {
            $this->policy->requireWrite($companyId, $actorUserId);
            $hint = $this->em->getRepository(SettlementDocument::class)->findOneBy(['companyId' => $companyId, 'id' => $documentId]);
            if (null === $hint) {
                throw new \DomainException('Документ не найден.');
            }
            $source = null;
            if (SettlementDocumentStatus::POSTED !== $hint->getStatus() && SettlementSourceType::FINANCE === $hint->getSourceType() && null !== $hint->getSourceId()) {
                $source = $this->finance->getSettlementSourceForUpdate($companyId, $hint->getSourceId());
            }
            $book = $this->repository->getBookForUpdate($companyId);
            $document = $this->repository->getDocumentForUpdate($companyId, $documentId);
            if (!in_array($document->getKind(), [SettlementDocumentKind::OBLIGATION, SettlementDocumentKind::OPENING], true)) {
                throw new \DomainException('Тип документа требует отдельного сценария проведения.');
            }
            if (SettlementDocumentStatus::POSTED === $document->getStatus()) {
                return new SettlementResult($documentId, $this->repository->findItemByDocument($companyId, $documentId)?->getId(), $document->getVersion(), true);
            }
            $link = null;
            $this->posting->assertBaseDocument($document->getKind(), $document->getBucket(), $document->getSourceType(), $document->getSourceId(), $document->getEffectiveDate(), $book->getStartDate());
            if (SettlementSourceType::FINANCE === $document->getSourceType()) {
                if (null === $source) {
                    throw new \DomainException('Не удалось проверить источник ОПиУ.');
                }
                $this->sourcePolicy->assertUsable($source);
                if (($document->getSourceSnapshot()['fingerprint'] ?? null) !== $source->fingerprint) {
                    throw new \DomainException('Источник ОПиУ изменён после создания черновика.');
                }
                $link = $this->em->getRepository(SettlementSourceLink::class)->findOneBy(['companyId' => $companyId, 'sourceType' => SettlementSourceType::FINANCE, 'sourceId' => $source->id]);
                if (null !== $link) {
                    $this->em->refresh($link);
                }
                if (null !== $link?->getLastReversalDate() && $document->getEffectiveDate()->format('Y-m-d') < $link->getLastReversalDate()->format('Y-m-d')) {
                    throw new \DomainException('Повторное основание раньше последней отмены.');
                }
                $link ??= new SettlementSourceLink($companyId, SettlementSourceType::FINANCE, $source->id);
                $link->activate($documentId);
            }
            $this->policy->assertDate($companyId, $book, $document->getEffectiveDate());
            $document->post($expectedVersion, $book->takeEventSequence());
            $item = new SettlementItem($companyId, $documentId, $document->getCounterpartyId(), $document->getBucket(), $document->getAmount(), $document->getEffectiveDate(), $document->getDueDate());
            $this->em->persist($item);
            if (null !== $link) {
                $this->em->persist($link);
            }
            $this->updater->append($document, $item, $item->getAmount());
            $book->initialize();

            return new SettlementResult($documentId, $item->getId(), $document->getVersion(), false);
        });
    }
}

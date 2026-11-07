<?php

declare(strict_types=1);

namespace App\Settlement\Controller;

use App\Company\Facade\CounterpartyFacade;
use App\Company\Security\ModuleAccess;
use App\Settlement\Query\SettlementUiQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settlements/documents/{id}', name: 'settlement_document', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_READ)]
final class SettlementDocumentShowController extends AbstractController
{
    public function __invoke(string $id, ActiveCompanyService $activeCompany, SettlementUiQuery $query, CounterpartyFacade $parties): Response
    {
        $company = $activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();
        $document = $query->document($companyId, $id) ?? throw $this->createNotFoundException('Документ не найден.');
        $labels = $parties->getLabelsByIds($companyId, [$document->getCounterpartyId()]);
        $positions = $query->positions($companyId, $document->getCounterpartyId(), $id);
        $allocation = $query->allocationByDocument($companyId, $id);
        $obligationDocument = null === $allocation ? null : $query->item($companyId, $allocation->getObligationItemId())?->getDocumentId();
        $paymentDocument = null === $allocation ? null : $query->item($companyId, $allocation->getPaymentItemId())?->getDocumentId();

        return $this->render('settlement/document.html.twig', ['activeCompany' => $company, 'book' => $query->book($companyId), 'document' => $document, 'counterpartyName' => $labels[$document->getCounterpartyId()] ?? 'Контрагент недоступен', 'movements' => $query->documentMovements($companyId, $id), 'reversal' => $query->reversal($companyId, $id), 'allocation' => $allocation, 'obligationDocument' => $obligationDocument, 'paymentDocument' => $paymentDocument, 'position' => $positions[0] ?? null, 'canWrite' => $this->isGranted(ModuleAccess::FINANCE_WRITE)]);
    }
}

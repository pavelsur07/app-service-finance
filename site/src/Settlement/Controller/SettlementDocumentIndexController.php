<?php

declare(strict_types=1);

namespace App\Settlement\Controller;

use App\Company\Facade\CounterpartyFacade;
use App\Company\Security\ModuleAccess;
use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Query\SettlementUiQuery;
use App\Shared\Service\ActiveCompanyService;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\Pagerfanta;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settlements/documents', name: 'settlement_documents', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_READ)]
final class SettlementDocumentIndexController extends AbstractController
{
    public function __invoke(Request $request, ActiveCompanyService $activeCompany, SettlementUiQuery $query, CounterpartyFacade $parties): Response
    {
        $company = $activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();
        $parameters = $request->query->all();
        $page = filter_var($parameters['page'] ?? '1', \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $limit = filter_var($parameters['limit'] ?? '50', \FILTER_VALIDATE_INT);
        $party = $parameters['counterparty'] ?? null;
        if (false === $page || !in_array($limit, [25, 50, 100], true) || (null !== $party && (!is_string($party) || !Uuid::isValid($party)))) {
            throw new UnprocessableEntityHttpException('Некорректные параметры списка.');
        }
        if (null !== $party && [] === $parties->getLabelsByIds($companyId, [$party])) {
            throw $this->createNotFoundException();
        }
        try {
            $pager = Pagerfanta::createForCurrentPageWithMaxPerPage(new QueryAdapter($query->documents($companyId, $party)), $page, $limit);
        } catch (OutOfRangeCurrentPageException $exception) {
            throw new UnprocessableEntityHttpException('Страница не найдена.', $exception);
        }
        $documents = [];
        $ids = [];
        $documentIds = [];
        /** @var iterable<SettlementDocument> $pageDocuments */ $pageDocuments = $pager->getCurrentPageResults();
        foreach ($pageDocuments as $document) {
            $documents[] = $document;
            $ids[] = $document->getCounterpartyId();
            $documentIds[] = $document->getId();
        }

        return $this->render('settlement/documents.html.twig', ['activeCompany' => $company, 'book' => $query->book($companyId), 'documents' => $documents, 'labels' => $parties->getLabelsByIds($companyId, array_values(array_unique($ids))), 'reversalIds' => $query->reversalIds($companyId, $documentIds), 'pager' => $pager, 'canWrite' => $this->isGranted(ModuleAccess::FINANCE_WRITE)]);
    }
}

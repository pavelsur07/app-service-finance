<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MoySklad\Infrastructure\Query\MappingBulkJobQuery;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Отчёт пакета: итог по каждой строке с фильтром по итогу. */
#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class ShowMappingBulkJobController extends AbstractController
{
    #[Route('/moy-sklad/connections/{connectionId}/mappings/{marketplace}/bulk/{jobId}', name: 'moysklad_mapping_bulk_show', requirements: ['connectionId' => Requirement::UUID, 'marketplace' => 'ozon|wildberries', 'jobId' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $connectionId, string $marketplace, string $jobId, Request $request, ActiveCompanyService $activeCompany,
        MoySkladConnectionWriteRepository $connections, MappingBulkJobQuery $jobs, MarketplaceFacade $listings): Response
    {
        $connectionId = strtolower($connectionId);
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $connection = $connections->findByIdAndCompanyId($connectionId, $companyId) ?? throw $this->createNotFoundException();
        $job = $jobs->find($companyId, $connectionId, $marketplace, strtolower($jobId)) ?? throw $this->createNotFoundException();
        $status = $request->query->all()['status'] ?? '';
        $page = $request->query->getInt('page', 1);
        if (!is_string($status) || ('' !== $status && !in_array($status, MappingBulkJobQuery::ITEM_STATUSES, true)) || $page < 1) {
            throw new UnprocessableEntityHttpException('Некорректный фильтр.');
        }
        $items = $jobs->items($companyId, $job->id, '' === $status ? null : $status, $page);

        return $this->render('moy_sklad/mappings/bulk.html.twig', [
            'job' => $job, 'items' => $items['items'], 'total' => $items['total'], 'page' => $page, 'status' => $status,
            'listings' => $listings->getListingsMetaByIds($companyId, array_column($items['items'], 'listing_id')),
            'connection' => $connection, 'mappingConnectionId' => $connectionId, 'mappingMarketplace' => $marketplace, 'mappingCompanyId' => $companyId,
            'providerName' => MarketplaceType::from($marketplace)->getDisplayName(),
        ]);
    }
}

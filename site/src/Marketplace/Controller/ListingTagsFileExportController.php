<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Application\DTO\ListingsFilter;
use App\Marketplace\Application\ImportListingTagsAction;
use App\Marketplace\DTO\ListingTagDTO;
use App\Marketplace\Infrastructure\Export\ListingTagsXlsxExporter;
use App\Marketplace\Infrastructure\Query\MarketplaceListingsQuery;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Файл разметки тегов по текущему фильтру реестра: правится в Excel и загружается обратно. */
#[Route('/marketplace/listings/tags-file', name: 'marketplace_listing_tags_file_export', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class ListingTagsFileExportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly MarketplaceListingsQuery $listingsQuery,
        private readonly MarketplaceListingTagRepository $tagRepository,
        private readonly ListingTagsXlsxExporter $exporter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->companyService->getActiveCompany()->getId();
        $filter = ListingsFilter::fromQuery($request->query->all());

        $rows = $this->listingsQuery->exportRows($companyId, $filter, ImportListingTagsAction::MAX_ROWS + 1);
        if (\count($rows) > ImportListingTagsAction::MAX_ROWS) {
            $this->addFlash('danger', sprintf('Под фильтр попадает больше %d листингов. Сузьте выборку и скачайте файл частями.', ImportListingTagsAction::MAX_ROWS));

            return $this->redirectToRoute('marketplace_listings_index', $filter->toQueryParams());
        }

        $file = tempnam(sys_get_temp_dir(), 'listing_tags_');
        if (false === $file) {
            throw new \RuntimeException('Unable to create temporary file for export');
        }

        try {
            $this->exporter->export(
                $rows,
                array_map(static fn (ListingTagDTO $tag): string => $tag->name, $this->tagRepository->listForCompany($companyId)),
                $file,
            );
        } catch (\Throwable $exception) {
            @unlink($file);

            throw $exception;
        }

        $response = new BinaryFileResponse($file);
        $response->deleteFileAfterSend(true);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, sprintf('listing-tags_%s.xlsx', date('Y-m-d')));
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }
}

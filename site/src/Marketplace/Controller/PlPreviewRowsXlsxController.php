<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Application\Action\BuildPlPreviewRowsExportAction;
use App\Marketplace\Application\PlPreview\PlPreviewFilter;
use App\Marketplace\Exception\MarketplacePlPreviewPeriodException;
use App\Marketplace\Infrastructure\Export\PlPreviewRowsXlsxExporter;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Выгрузка предпросмотра ОПиУ строками для план-факта: тот же фильтр, что на вкладке, отчёт по
 * каждому каналу. Пустые строки дерева выгружаются всегда — параметр `empty` не влияет.
 */
#[Route('/marketplace/pl-preview/rows-xlsx', name: 'marketplace_pl_preview_rows_xlsx', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class PlPreviewRowsXlsxController extends AbstractController
{
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly BuildPlPreviewRowsExportAction $buildExport,
        private readonly PlPreviewRowsXlsxExporter $exporter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->companyService->getActiveCompany()->getId();
        $now = $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $filter = PlPreviewFilter::fromQuery($request->query->all(), $now);

        try {
            $export = ($this->buildExport)($filter->toCommand($companyId));
        } catch (MarketplacePlPreviewPeriodException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('marketplace_pl_preview_index', $filter->toQuery());
        }

        $file = tempnam(sys_get_temp_dir(), 'pl_rows_export_');
        if (false === $file) {
            throw new \RuntimeException('Unable to create temporary file for export');
        }

        try {
            $this->exporter->export($export, $now, $file);
        } catch (\Throwable $exception) {
            if (is_file($file)) {
                unlink($file);
            }

            throw $exception;
        }

        $response = new BinaryFileResponse($file);
        $response->deleteFileAfterSend(true);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setContentDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('pl-rows_%s_%s.xlsx', $export->from->format('Y-m-d'), $export->to->format('Y-m-d')),
        );
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Accept-Ranges', 'none');

        return $response;
    }
}

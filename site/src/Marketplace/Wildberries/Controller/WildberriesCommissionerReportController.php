<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Controller;

use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use App\Marketplace\Wildberries\Form\WildberriesCommissionerReportUploadType;
use App\Marketplace\Wildberries\Message\WbCommissionerXlsxImportMessage;
use App\Marketplace\Wildberries\CommissionerReport\Entity\WbAggregationResult;
use App\Marketplace\Wildberries\CommissionerReport\Entity\WbDimensionValue;
use App\Marketplace\Wildberries\CommissionerReport\Entity\WbCostMapping;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbCostMappingRepository;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbCostTypeRepository;
use App\Marketplace\Wildberries\Service\CommissionerReport\WbCommissionerXlsxFormatValidator;
use App\Repository\PLCategoryRepository;
use App\Service\ActiveCompanyService;
use App\Service\Storage\StorageService;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/marketplace/wb/commissioner-reports', name: 'wb_commissioner_reports_')]
final class WildberriesCommissionerReportController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ActiveCompanyService $companyContext,
        private readonly StorageService $storageService,
        private readonly WbCommissionerXlsxFormatValidator $formatValidator,
        private readonly MessageBusInterface $bus,
        private readonly PLCategoryRepository $plCategoryRepository,
        private readonly WbCostTypeRepository $costTypeRepository,
        private readonly WbCostMappingRepository $costMappingRepository,
    ) {
    }

    #[Route(path: '', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->companyContext->getActiveCompany();
        $reports = $this->doctrine->getRepository(WildberriesCommissionerXlsxReport::class)
            ->findBy(['company' => $company], ['createdAt' => 'DESC']);

        return $this->render('wildberries/commissioner_report/index.html.twig', [
            'reports' => $reports,
        ]);
    }

    #[Route(path: '/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->createForm(WildberriesCommissionerReportUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $file = $data['file'] ?? null;

            if ($file instanceof UploadedFile) {
                $company = $this->companyContext->getActiveCompany();
                $reportId = Uuid::uuid4()->toString();
                $storagePath = sprintf(
                    'marketplace/wildberries/commissioner-reports/%s/%s.xlsx',
                    $company->getId(),
                    $reportId
                );

                $stored = $this->storageService->storeUploadedFile($file, $storagePath);
                $validationResult = $this->formatValidator->validate(
                    $this->storageService->getAbsolutePath($stored['storagePath'])
                );

                $report = new WildberriesCommissionerXlsxReport($reportId, $company, new \DateTimeImmutable());
                $report->setPeriodStart($data['periodStart']);
                $report->setPeriodEnd($data['periodEnd']);
                $report->setStoragePath($stored['storagePath']);
                $report->setFileHash($stored['fileHash']);
                $report->setOriginalFilename($stored['originalFilename']);
                $report->setHeadersHash($validationResult->headersHash);
                $report->setFormatStatus($validationResult->status);
                $report->setWarningsJson([] !== $validationResult->warnings ? $validationResult->warnings : null);
                $report->setErrorsJson([] !== $validationResult->errors ? $validationResult->errors : null);
                $report->setWarningsCount(count($validationResult->warnings));
                $report->setErrorsCount(count($validationResult->errors));
                $report->setStatus('uploaded');
                if (WbCommissionerXlsxFormatValidator::STATUS_FAILED === $validationResult->status) {
                    $report->setStatus('failed');
                }

                $em = $this->doctrine->getManager();
                $em->persist($report);
                $em->flush();

                if (WbCommissionerXlsxFormatValidator::STATUS_FAILED === $validationResult->status) {
                    $this->addFlash(
                        'danger',
                        'Отчёт загружен, но формат не распознан. Открой карточку отчёта и посмотри ошибки валидации.'
                    );
                } else {
                    $this->addFlash('success', 'Отчёт загружен и сохранён.');
                }

                return $this->redirectToRoute('wb_commissioner_reports_show', ['id' => $reportId]);
            }

            $this->addFlash('danger', 'Файл не был загружен.');
        }

        return $this->render('wildberries/commissioner_report/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $company = $this->companyContext->getActiveCompany();
        $report = $this->doctrine->getRepository(WildberriesCommissionerXlsxReport::class)
            ->findOneBy(['id' => $id, 'company' => $company]);

        if (!$report instanceof WildberriesCommissionerXlsxReport) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        $aggregationRepository = $this->doctrine->getRepository(WbAggregationResult::class);
        $mappedResults = $aggregationRepository->createQueryBuilder('result')
            ->select('costType.id AS costTypeId', 'costType.title AS costTypeTitle', 'SUM(result.amount) AS amount')
            ->innerJoin('result.costType', 'costType')
            ->andWhere('result.company = :company')
            ->andWhere('result.report = :report')
            ->andWhere('result.status = :status')
            ->setParameter('company', $company)
            ->setParameter('report', $report)
            ->setParameter('status', 'mapped')
            ->groupBy('costType.id', 'costType.title')
            ->orderBy('costType.title', 'ASC')
            ->getQuery()
            ->getArrayResult();
        $unmappedResults = $aggregationRepository->createQueryBuilder('result')
            ->select(
                'dimension.id AS dimensionValueId',
                'dimension.dimensionKey AS dimensionKey',
                'dimension.value AS dimensionValue',
                'SUM(result.amount) AS amount'
            )
            ->innerJoin('result.dimensionValue', 'dimension')
            ->andWhere('result.company = :company')
            ->andWhere('result.report = :report')
            ->andWhere('result.status = :status')
            ->setParameter('company', $company)
            ->setParameter('report', $report)
            ->setParameter('status', 'unmapped')
            ->groupBy('dimension.id', 'dimension.dimensionKey', 'dimension.value')
            ->orderBy('dimension.dimensionKey', 'ASC')
            ->addOrderBy('dimension.value', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $costTypes = $this->costTypeRepository->createQueryBuilder('costType')
            ->andWhere('costType.company = :company')
            ->andWhere('costType.isActive = true')
            ->setParameter('company', $company)
            ->orderBy('costType.title', 'ASC')
            ->getQuery()
            ->getResult();

        $categories = $this->plCategoryRepository->findTreeByCompany($company);
        $categoryOptions = [];

        foreach ($categories as $category) {
            $depth = max(0, $category->getLevel() - 1);
            $categoryOptions[] = [
                'id' => (string) $category->getId(),
                'name' => $category->getName(),
                'depth' => $depth,
                'label' => str_repeat('— ', $depth).$category->getName(),
            ];
        }

        $dimensionValueIds = array_values(array_filter(array_map(
            static fn (array $item): ?string => $item['dimensionValueId'] ?? null,
            $unmappedResults
        )));

        $dimensionValues = [];
        if ([] !== $dimensionValueIds) {
            $dimensionValues = $this->doctrine->getRepository(WbDimensionValue::class)
                ->createQueryBuilder('dimension')
                ->andWhere('dimension.company = :company')
                ->andWhere('dimension.report = :report')
                ->andWhere('dimension.id IN (:dimensionValueIds)')
                ->setParameter('company', $company)
                ->setParameter('report', $report)
                ->setParameter('dimensionValueIds', $dimensionValueIds)
                ->getQuery()
                ->getResult();
        }

        $costMappings = $this->costMappingRepository->findByDimensionValues($company, $dimensionValues);
        $mappingByDimensionId = [];

        foreach ($costMappings as $mapping) {
            $mappingByDimensionId[$mapping->getDimensionValue()->getId()] = $mapping;
        }

        $hasMappingGaps = false;
        foreach ($unmappedResults as $item) {
            $dimensionValueId = $item['dimensionValueId'] ?? null;
            if (null === $dimensionValueId) {
                continue;
            }

            if (!isset($mappingByDimensionId[$dimensionValueId])) {
                $hasMappingGaps = true;
                break;
            }
        }

        return $this->render('wildberries/commissioner_report/show.html.twig', [
            'report' => $report,
            'mappedResults' => $mappedResults,
            'unmappedResults' => $unmappedResults,
            'hasUnmapped' => [] !== $unmappedResults,
            'costTypes' => $costTypes,
            'categoryOptions' => $categoryOptions,
            'mappingByDimensionId' => $mappingByDimensionId,
            'canCreatePnl' => [] === $unmappedResults && !$hasMappingGaps,
        ]);
    }

    #[Route(path: '/{id}/process', name: 'process', methods: ['POST'])]
    public function process(Request $request, string $id): Response
    {
        $company = $this->companyContext->getActiveCompany();
        $report = $this->doctrine->getRepository(WildberriesCommissionerXlsxReport::class)
            ->findOneBy(['id' => $id, 'company' => $company]);

        if (!$report instanceof WildberriesCommissionerXlsxReport) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        if (!$this->isCsrfTokenValid('wb_commissioner_report_process'.$report->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF-токен при запуске обработки.');

            return $this->redirectToRoute('wb_commissioner_reports_show', ['id' => $report->getId()]);
        }

        $this->bus->dispatch(new WbCommissionerXlsxImportMessage((string) $company->getId(), $report->getId()));

        $this->addFlash('success', 'Отчёт отправлен на обработку.');

        return $this->redirectToRoute('wb_commissioner_reports_show', ['id' => $report->getId()]);
    }

    #[Route(path: '/{id}/mappings', name: 'mappings', methods: ['POST'])]
    public function saveMappings(Request $request, string $id): Response
    {
        $company = $this->companyContext->getActiveCompany();
        $report = $this->doctrine->getRepository(WildberriesCommissionerXlsxReport::class)
            ->findOneBy(['id' => $id, 'company' => $company]);

        if (!$report instanceof WildberriesCommissionerXlsxReport) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        if (!$this->isCsrfTokenValid('wb_commissioner_report_mappings'.$report->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF-токен при сохранении сопоставлений.');

            return $this->redirectToRoute('wb_commissioner_reports_show', ['id' => $report->getId()]);
        }

        $mappings = $request->request->all('mappings');
        if (!is_array($mappings)) {
            $this->addFlash('warning', 'Нет данных для сохранения сопоставлений.');

            return $this->redirectToRoute('wb_commissioner_reports_show', ['id' => $report->getId()]);
        }

        $dimensionValueIds = [];
        $costTypeIds = [];
        $plCategoryIds = [];

        foreach ($mappings as $mappingData) {
            if (!is_array($mappingData)) {
                continue;
            }

            $dimensionValueId = $mappingData['dimensionValueId'] ?? null;
            $costTypeId = $mappingData['costTypeId'] ?? null;
            $plCategoryId = $mappingData['plCategoryId'] ?? null;

            if ($dimensionValueId) {
                $dimensionValueIds[] = (string) $dimensionValueId;
            }
            if ($costTypeId) {
                $costTypeIds[] = (string) $costTypeId;
            }
            if ($plCategoryId) {
                $plCategoryIds[] = (string) $plCategoryId;
            }
        }

        $dimensionValueIds = array_values(array_unique($dimensionValueIds));
        $costTypeIds = array_values(array_unique($costTypeIds));
        $plCategoryIds = array_values(array_unique($plCategoryIds));

        $dimensionValuesById = [];
        if ([] !== $dimensionValueIds) {
            $dimensionValues = $this->doctrine->getRepository(WbDimensionValue::class)
                ->createQueryBuilder('dimension')
                ->andWhere('dimension.company = :company')
                ->andWhere('dimension.report = :report')
                ->andWhere('dimension.id IN (:ids)')
                ->setParameter('company', $company)
                ->setParameter('report', $report)
                ->setParameter('ids', $dimensionValueIds)
                ->getQuery()
                ->getResult();

            foreach ($dimensionValues as $dimensionValue) {
                $dimensionValuesById[$dimensionValue->getId()] = $dimensionValue;
            }
        }

        $costTypesById = [];
        if ([] !== $costTypeIds) {
            $costTypes = $this->costTypeRepository->createQueryBuilder('costType')
                ->andWhere('costType.company = :company')
                ->andWhere('costType.id IN (:ids)')
                ->setParameter('company', $company)
                ->setParameter('ids', $costTypeIds)
                ->getQuery()
                ->getResult();

            foreach ($costTypes as $costType) {
                $costTypesById[$costType->getId()] = $costType;
            }
        }

        $plCategoriesById = [];
        if ([] !== $plCategoryIds) {
            $plCategories = $this->plCategoryRepository->createQueryBuilder('category')
                ->andWhere('category.company = :company')
                ->andWhere('category.id IN (:ids)')
                ->setParameter('company', $company)
                ->setParameter('ids', $plCategoryIds)
                ->getQuery()
                ->getResult();

            foreach ($plCategories as $category) {
                $plCategoriesById[$category->getId()] = $category;
            }
        }

        $em = $this->doctrine->getManager();

        foreach ($mappings as $mappingData) {
            if (!is_array($mappingData)) {
                continue;
            }

            $dimensionValueId = $mappingData['dimensionValueId'] ?? null;
            $costTypeId = $mappingData['costTypeId'] ?? null;
            $plCategoryId = $mappingData['plCategoryId'] ?? null;

            if (!$dimensionValueId || !$costTypeId || !$plCategoryId) {
                continue;
            }

            $dimensionValue = $dimensionValuesById[(string) $dimensionValueId] ?? null;
            $costType = $costTypesById[(string) $costTypeId] ?? null;
            $plCategory = $plCategoriesById[(string) $plCategoryId] ?? null;

            if (!$dimensionValue || !$costType || !$plCategory) {
                continue;
            }

            $mapping = $this->costMappingRepository->findOneBy([
                'company' => $company,
                'dimensionValue' => $dimensionValue,
            ]);

            if (!$mapping instanceof WbCostMapping) {
                $mapping = new WbCostMapping(
                    Uuid::uuid4()->toString(),
                    $company,
                    $dimensionValue,
                    $costType,
                    $plCategory
                );
            } else {
                $mapping
                    ->setCostType($costType)
                    ->setPlCategory($plCategory)
                    ->setUpdatedAt(new \DateTimeImmutable());
            }

            $em->persist($mapping);
        }

        $em->flush();

        $this->addFlash('success', 'Сопоставления сохранены.');

        return $this->redirectToRoute('wb_commissioner_reports_show', ['id' => $report->getId()]);
    }
}

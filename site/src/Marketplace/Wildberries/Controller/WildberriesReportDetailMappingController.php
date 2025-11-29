<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Controller;

use App\Marketplace\Wildberries\Entity\WildberriesReportDetailMapping;
use App\Marketplace\Wildberries\Repository\WildberriesReportDetailMappingRepository;
use App\Marketplace\Wildberries\Repository\WildberriesReportDetailRepository;
use App\Marketplace\Wildberries\Service\WildberriesReportDetailMappingResolver;
use App\Marketplace\Wildberries\Service\WildberriesWeeklyPnlGenerator;
use App\Repository\PLCategoryRepository;
use App\Service\ActiveCompanyService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/wb/report_detail/mapping', name: 'wb_report_detail_mapping_')]
final class WildberriesReportDetailMappingController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly WildberriesReportDetailMappingRepository $mappingRepository,
        private readonly WildberriesReportDetailMappingResolver $mappingResolver,
        private readonly WildberriesReportDetailRepository $detailRepository,
        private readonly PLCategoryRepository $plCategoryRepository,
        private readonly WildberriesWeeklyPnlGenerator $weeklyPnlGenerator,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(path: '', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();

        $fromParam = $request->query->get('from');
        $toParam = $request->query->get('to');

        $to = $toParam ? new DateTimeImmutable((string) $toParam) : new DateTimeImmutable('today');
        $from = $fromParam ? new DateTimeImmutable((string) $fromParam) : $to->modify('-6 days');

        $sourceFieldOptions = [
            // Цена за единицу
            'retail_price',
            // Сумма реализации (берём из raw)
            'retail_amount',
            // Эквайринг / комиссии за платежи
            'acquiring_fee',
            // Цена продажи с учётом скидки
            'retailPriceWithDiscRub',
            // Стоимость доставки
            'deliveryRub',
            // Плата за хранение
            'storageFee',
            // Штрафы
            'penalty',
            // Эквайринг (альтернативное поле)
            'acquiringFee',
        ];

        $combinations = $this->mappingResolver->collectDistinctKeysForCompany(
            $company,
            $from,
            $to,
            $this->detailRepository
        );

        // карта "combinationKey => rowsCount"
        $combinationCounts = [];
        foreach ($combinations as $combination) {
            $combinationKey = sprintf(
                '%s|%s|%s',
                (string) ($combination['supplierOperName'] ?? ''),
                (string) ($combination['docTypeName'] ?? ''),
                (string) ($combination['siteCountry'] ?? '')
            );
            $combinationCounts[$combinationKey] = $combination['rowsCount'];
        }

        // группируем существующие маппинги по комбинации
        $mappingsByCombination = [];
        foreach ($this->mappingRepository->findAllByCompany($company) as $mapping) {
            $combinationKey = sprintf(
                '%s|%s|%s',
                (string) $mapping->getSupplierOperName(),
                (string) $mapping->getDocTypeName(),
                (string) $mapping->getSiteCountry()
            );

            $mappingsByCombination[$combinationKey][] = $mapping;
        }

        // строим items: по одному item на каждый mapping;
        // плюс по одному пустому item для комбинаций без маппинга
        $items = [];

        foreach ($combinations as $combination) {
            $combinationKey = sprintf(
                '%s|%s|%s',
                (string) ($combination['supplierOperName'] ?? ''),
                (string) ($combination['docTypeName'] ?? ''),
                (string) ($combination['siteCountry'] ?? '')
            );

            $rowsCount = $combinationCounts[$combinationKey] ?? $combination['rowsCount'];

            if (!empty($mappingsByCombination[$combinationKey])) {
                foreach ($mappingsByCombination[$combinationKey] as $mapping) {
                    $items[] = [
                        'supplierOperName' => $combination['supplierOperName'],
                        'docTypeName' => $combination['docTypeName'],
                        'siteCountry' => $combination['siteCountry'],
                        'rowsCount' => $rowsCount,
                        'mapping' => $mapping,
                    ];
                }
            } else {
                // нет ни одного правила для этой комбинации — добавляем пустую строку
                $items[] = [
                    'supplierOperName' => $combination['supplierOperName'],
                    'docTypeName' => $combination['docTypeName'],
                    'siteCountry' => $combination['siteCountry'],
                    'rowsCount' => $rowsCount,
                    'mapping' => null,
                ];
            }
        }

        $categories = $this->plCategoryRepository->findTreeByCompany($company);
        $categoryOptions = [];
        $categoryById = [];

        foreach ($categories as $category) {
            $depth = max(0, $category->getLevel() - 1);
            $categoryOptions[] = [
                'id' => (string) $category->getId(),
                'name' => $category->getName(),
                'depth' => $depth,
                'label' => str_repeat('— ', $depth).$category->getName(),
            ];

            $categoryById[(string) $category->getId()] = $category;
        }

        $aggregated = $this->weeklyPnlGenerator->aggregateForPeriod($company, $from, $to);

        return $this->render('wb/report_detail/mapping.html.twig', [
            'company' => $company,
            'from' => $from,
            'to' => $to,
            'items' => $items,
            'categories' => $categories,
            'categoryOptions' => $categoryOptions,
            'categoryById' => $categoryById,
            'sourceFieldOptions' => $sourceFieldOptions,
            'totals' => $aggregated['totals'],
            'unmapped' => $aggregated['unmapped'],
        ]);
    }

    #[Route(path: '/save', name: 'save', methods: ['POST'])]
    public function saveMappings(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();

        if (!$this->isCsrfTokenValid('wb_report_detail_mapping', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $mappings = $request->request->all('mappings');

        foreach ($mappings as $mappingData) {
            $plCategoryId = $mappingData['plCategoryId'] ?? null;
            $supplierOperName = $mappingData['supplierOperName'] ?? null;
            $docTypeName = $mappingData['docTypeName'] ?? null;
            $siteCountry = $mappingData['siteCountry'] ?? null;
            $sourceField = $mappingData['sourceField'] ?? null;

            $mapping = null;

            if ($supplierOperName !== null && $sourceField !== null) {
                $mapping = $this->mappingRepository->findOneByKeyAndSourceField(
                    $company,
                    $supplierOperName,
                    $docTypeName !== '' ? $docTypeName : null,
                    $siteCountry !== '' ? $siteCountry : null,
                    (string) $sourceField
                );
            }

            if (empty($plCategoryId) && $mapping === null) {
                continue;
            }

            if (!$mapping instanceof WildberriesReportDetailMapping) {
                $mapping = new WildberriesReportDetailMapping(Uuid::uuid4()->toString(), $company);
                $mapping->setSupplierOperName($supplierOperName);
                $mapping->setDocTypeName($docTypeName !== '' ? $docTypeName : null);
                $mapping->setSiteCountry($siteCountry !== '' ? $siteCountry : null);
            }

            $mapping->setSourceField((string) $sourceField);

            $plCategory = $this->plCategoryRepository->find($plCategoryId);

            if (null === $plCategory) {
                continue;
            }

            $mapping
                ->setPlCategory($plCategory)
                ->setIsActive(!empty($mappingData['isActive']))
                ->setUpdatedAt(new DateTimeImmutable());

            $this->em->persist($mapping);
        }

        $this->em->flush();

        $this->addFlash('success', 'Маппинг Wildberries сохранён.');

        return $this->redirectToRoute('wb_report_detail_mapping_index', [
            'from' => $request->request->get('from'),
            'to' => $request->request->get('to'),
        ]);
    }

    #[Route(path: '/create-document', name: 'create_document', methods: ['POST'])]
    public function createWeeklyDocument(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();

        if (!$this->isCsrfTokenValid('wb_report_detail_mapping_create_document', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $from = new DateTimeImmutable((string) $request->request->get('from'));
        $to = new DateTimeImmutable((string) $request->request->get('to'));

        $result = $this->weeklyPnlGenerator->aggregateForPeriod($company, $from, $to);

        if ([] !== $result['unmapped']) {
            $this->addFlash('danger', 'Есть немапнутые операции Wildberries, сначала дополни маппинг.');

            return $this->redirectToRoute('wb_report_detail_mapping_index', [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ]);
        }

        $document = $this->weeklyPnlGenerator->createWeeklyDocumentFromTotals($company, $from, $to, $result['totals']);

        $this->addFlash('success', 'Документ ОПиУ по Wildberries успешно создан.');

        return $this->redirectToRoute('document_edit', ['id' => $document->getId()]);
    }
}

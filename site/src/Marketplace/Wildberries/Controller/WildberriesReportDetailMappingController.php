<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Controller;

use App\Marketplace\Wildberries\Entity\WildberriesReportDetailMapping;
use App\Marketplace\Wildberries\Repository\WildberriesReportDetailMappingRepository;
use App\Marketplace\Wildberries\Repository\WildberriesReportDetailRepository;
use App\Marketplace\Wildberries\Service\WildberriesReportDetailMappingResolver;
use App\Marketplace\Wildberries\Service\WildberriesReportDetailSourceFieldProvider;
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
        private readonly WildberriesReportDetailSourceFieldProvider $sourceFieldProvider,
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

        $sourceFieldOptions = $this->sourceFieldProvider->getOptions();

        $combinations = $this->mappingResolver->collectDistinctKeysForCompany(
            $company,
            $from,
            $to,
            $this->detailRepository
        );

        // карта "oper|docType => суммарный rowsCount"
        $combinationCounts = [];
        foreach ($combinations as $combination) {
            $combinationKey = sprintf(
                '%s|%s',
                (string) ($combination['supplierOperName'] ?? ''),
                (string) ($combination['docTypeName'] ?? '')
            );

            if (!isset($combinationCounts[$combinationKey])) {
                $combinationCounts[$combinationKey] = 0;
            }

            $combinationCounts[$combinationKey] += (int) $combination['rowsCount'];
        }

        // группируем существующие маппинги по oper|docType
        $mappingsByCombination = [];
        foreach ($this->mappingRepository->findAllByCompany($company) as $mapping) {
            $combinationKey = sprintf(
                '%s|%s',
                (string) $mapping->getSupplierOperName(),
                (string) $mapping->getDocTypeName()
            );

            $mappingsByCombination[$combinationKey][] = $mapping;
        }

        // строим items: по одному item на каждый mapping;
        // плюс по одному пустому item для комбинаций без маппинга
        $items = [];

        foreach ($combinationCounts as $combinationKey => $rowsCount) {
            [$supplierOperName, $docTypeName] = explode('|', $combinationKey);

            if (!empty($mappingsByCombination[$combinationKey])) {
                foreach ($mappingsByCombination[$combinationKey] as $mapping) {
                    $items[] = [
                        'supplierOperName' => $supplierOperName !== '' ? $supplierOperName : null,
                        'docTypeName' => $docTypeName !== '' ? $docTypeName : null,
                        'rowsCount' => $rowsCount,
                        'mapping' => $mapping,
                    ];
                }
            } else {
                // нет ни одного правила для этой комбинации — добавляем пустую строку
                $items[] = [
                    'supplierOperName' => $supplierOperName !== '' ? $supplierOperName : null,
                    'docTypeName' => $docTypeName !== '' ? $docTypeName : null,
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

        $deletedMappingIds = array_filter((array) $request->request->all('deletedMappings'));
        $mappings = $request->request->all('mappings');

        foreach ($deletedMappingIds as $mappingId) {
            $mapping = $this->mappingRepository->find($mappingId);

            if (!$mapping instanceof WildberriesReportDetailMapping) {
                continue;
            }

            if ($mapping->getCompany()->getId() !== $company->getId()) {
                continue;
            }

            $this->em->remove($mapping);
        }

        foreach ($mappings as $mappingData) {
            $mappingId = $mappingData['id'] ?? null;
            $plCategoryId = $mappingData['plCategoryId'] ?? null;
            $supplierOperName = $mappingData['supplierOperName'] ?? null;
            $docTypeName = $mappingData['docTypeName'] ?? null;
            $sourceField = $mappingData['sourceField'] ?? null;
            $note = $mappingData['note'] ?? null;
            $signMultiplier = isset($mappingData['signMultiplier']) ? (int) $mappingData['signMultiplier'] : 1;

            $mapping = $mappingId ? $this->mappingRepository->find($mappingId) : null;

            // Если поле суммы очищено и правило уже существует — удаляем его
            if (empty($sourceField)) {
                if ($mapping instanceof WildberriesReportDetailMapping) {
                    $this->em->remove($mapping);
                }

                continue;
            }

            // Если категория не выбрана и правила ещё нет — просто пропускаем строку
            if (empty($plCategoryId) && $mapping === null) {
                continue;
            }

            // Если категория очищена, но правило уже существует —
            // удаляем его и переходим к следующей строке
            if (empty($plCategoryId) && $mapping instanceof WildberriesReportDetailMapping) {
                $this->em->remove($mapping);
                continue;
            }

            if (!$mapping instanceof WildberriesReportDetailMapping) {
                $mapping = new WildberriesReportDetailMapping(Uuid::uuid4()->toString(), $company);
                $mapping->setSupplierOperName($supplierOperName);
                $mapping->setDocTypeName($docTypeName !== '' ? $docTypeName : null);
                $mapping->setSiteCountry(null);
            }

            $mapping->setSourceField((string) $sourceField);
            $mapping->setSignMultiplier($signMultiplier);
            $mapping->setNote($note !== '' ? $note : null);

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

    #[Route(path: '/clear', name: 'clear', methods: ['POST'])]
    public function clear(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();

        if (!$this->isCsrfTokenValid('wb_report_detail_mapping_clear', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $this->mappingRepository->deleteByCompany($company);

        $this->addFlash('success', 'Маппинг Wildberries очищен.');

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

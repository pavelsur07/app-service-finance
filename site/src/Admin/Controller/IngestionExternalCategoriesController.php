<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Ingestion\Application\Action\DiscoverExternalCategoriesAction;
use App\Ingestion\Application\Action\RefreshOzonAccrualCategoryMetadataAction;
use App\Ingestion\Application\Action\SeedExternalCategoryMappingsAction;
use App\Ingestion\Application\Action\UpdateExternalCategoryMappingAction;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Infrastructure\Query\ExternalCategoryAdminQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Webmozart\Assert\Assert;

#[Route('/admin/ingestion/external-categories', name: 'admin_ingestion_external_categories_')]
#[IsGranted('ROLE_ADMIN')]
final class IngestionExternalCategoriesController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ExternalCategoryAdminQuery $query): Response
    {
        return $this->render('admin/ingestion/external_categories/index.html.twig', [
            'status_summary' => $query->statusSummary(),
            'unclassified' => $query->unclassifiedOzonAccrualTransactions(),
            'latest_categories' => $query->latestCategories(),
            'transaction_types' => TransactionType::cases(),
            'mapping_statuses' => ExternalCategoryMappingStatus::cases(),
            'last_refresh' => null,
        ]);
    }

    #[Route('/seed-defaults', name: 'seed_defaults', methods: ['POST'])]
    public function seedDefaults(
        Request $request,
        SeedExternalCategoryMappingsAction $seedDefaults,
    ): Response {
        $this->validateCsrf($request, 'admin_ingestion_external_categories_seed_defaults');

        try {
            $stats = $seedDefaults(IngestSource::OZON);
            $this->addFlash('success', sprintf(
                'Базовые Ozon-маппинги обновлены: создано категорий %d, создано маппингов %d, уже существовало %d.',
                $stats['categoriesCreated'],
                $stats['mappingsCreated'],
                $stats['mappingsExisting'],
            ));
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_ingestion_external_categories_index');
    }

    #[Route('/discover', name: 'discover', methods: ['POST'])]
    public function discover(
        Request $request,
        DiscoverExternalCategoriesAction $discoverExternalCategories,
    ): Response {
        $this->validateCsrf($request, 'admin_ingestion_external_categories_discover');

        try {
            $stats = $discoverExternalCategories(IngestSource::OZON, $this->intValue($request->request->get('limit'), 500, 1, 5000));
            $this->addFlash('success', sprintf(
                'Discovery завершен: просканировано %d, создано категорий %d, автомаппинг %d, без маппинга %d.',
                $stats['scanned'],
                $stats['categoriesCreated'],
                $stats['autoMapped'],
                $stats['unmapped'],
            ));
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_ingestion_external_categories_index');
    }

    #[Route('/refresh-ozon-metadata', name: 'refresh_ozon_metadata', methods: ['POST'])]
    public function refreshOzonMetadata(
        Request $request,
        ExternalCategoryAdminQuery $query,
        RefreshOzonAccrualCategoryMetadataAction $refreshMetadata,
    ): Response {
        $this->validateCsrf($request, 'admin_ingestion_external_categories_refresh_ozon_metadata');

        try {
            $companyId = trim((string) $request->request->get('company_id', ''));
            Assert::uuid($companyId, 'Company ID должен быть UUID.');

            $from = $this->dateValue((string) $request->request->get('from', ''));
            $to = $this->dateValue((string) $request->request->get('to', ''));
            if ($from > $to) {
                throw new \InvalidArgumentException('Дата from не может быть позже даты to.');
            }

            $mode = (string) $request->request->get('mode', 'dry-run');
            if (!in_array($mode, ['dry-run', 'execute'], true)) {
                throw new \InvalidArgumentException('Некорректный режим refresh.');
            }

            $result = $refreshMetadata(
                companyId: $companyId,
                from: $from,
                to: $to,
                shopRef: $this->optionalString($request->request->get('shop_ref')),
                limit: $this->intValue($request->request->get('limit'), 100, 1, 500),
                dryRun: 'dry-run' === $mode,
            );

            $updated = array_sum(array_map(static fn (array $row): int => (int) $row['updated'], $result['results']));
            $failed = count(array_filter($result['results'], static fn (array $row): bool => 'error' === $row['status']));
            $this->addFlash(
                0 === $failed ? 'success' : 'error',
                sprintf(
                    '%s refresh metadata: raw records %d, updated %d, failed %d.',
                    'dry-run' === $mode ? 'Dry-run' : 'Execute',
                    count($result['rawRecords']),
                    $updated,
                    $failed,
                ),
            );

            return $this->render('admin/ingestion/external_categories/index.html.twig', [
                'status_summary' => $query->statusSummary(),
                'unclassified' => $query->unclassifiedOzonAccrualTransactions(),
                'latest_categories' => $query->latestCategories(),
                'transaction_types' => TransactionType::cases(),
                'mapping_statuses' => ExternalCategoryMappingStatus::cases(),
                'last_refresh' => $result + ['mode' => $mode],
            ]);
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('admin_ingestion_external_categories_index');
        }
    }

    #[Route('/mapping/{id}', name: 'update_mapping', methods: ['POST'])]
    public function updateMapping(
        string $id,
        Request $request,
        UpdateExternalCategoryMappingAction $updateMapping,
    ): Response {
        $this->validateCsrf($request, sprintf('admin_ingestion_external_categories_update_mapping_%s', $id));

        try {
            Assert::uuid($id, 'Category ID должен быть UUID.');
            $updateMapping(
                categoryId: $id,
                canonicalCode: trim((string) $request->request->get('canonical_code', '')),
                canonicalLabel: trim((string) $request->request->get('canonical_label', '')),
                canonicalGroup: trim((string) $request->request->get('canonical_group', '')),
                transactionType: TransactionType::from((string) $request->request->get('transaction_type')),
                sortOrder: $this->intValue($request->request->get('sort_order'), 9000, 1, 100000),
                status: ExternalCategoryMappingStatus::from((string) $request->request->get('mapping_status')),
                known: '1' === (string) $request->request->get('known', '0'),
            );
            $this->addFlash('success', 'Маппинг категории сохранен. Запустите refresh metadata для нужного периода.');
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_ingestion_external_categories_index');
    }

    private function validateCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Недействительный CSRF токен.');
        }
    }

    private function dateValue(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if (false === $date || $date->format('Y-m-d') !== trim($value)) {
            throw new \InvalidArgumentException('Дата должна быть в формате YYYY-MM-DD.');
        }

        return $date;
    }

    private function intValue(mixed $value, int $default, int $min, int $max): int
    {
        if (null === $value || '' === trim((string) $value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private function optionalString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Marketplace\Application\RunUserReconciliationAction;
use App\Marketplace\Entity\ReconciliationSession;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Загрузка xlsx и запуск сверки затрат маркетплейса.
 *
 * TODO: если сверка станет долгой (>5с), вынести run в async Message/Handler
 */
#[IsGranted('ROLE_USER')]
final class ReconciliationUploadController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly StorageService $storageService,
        private readonly RunUserReconciliationAction $reconciliationAction,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/marketplace/reconciliation/upload', name: 'api_marketplace_reconciliation_upload', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $file        = $request->files->get('file');
        $periodFrom  = $request->request->get('periodFrom', '');
        $periodTo    = $request->request->get('periodTo', '');
        $marketplace = $request->request->get('marketplace', 'ozon');

        // --- Валидация ---

        if ($file === null) {
            return $this->json(['error' => 'Файл не загружен.'], 400);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension !== 'xlsx') {
            return $this->json(['error' => 'Допустимый формат: .xlsx'], 400);
        }

        if ($file->getSize() > 50 * 1024 * 1024) {
            return $this->json(['error' => 'Максимальный размер файла: 50 МБ.'], 400);
        }

        $dateFrom = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodFrom);
        $dateTo   = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodTo);

        if ($dateFrom === false || $dateTo === false) {
            return $this->json(['error' => 'Некорректный формат дат. Ожидается Y-m-d.'], 400);
        }

        if ($dateFrom >= $dateTo) {
            return $this->json(['error' => 'periodFrom должен быть раньше periodTo.'], 400);
        }

        if (MarketplaceType::tryFrom($marketplace) === null) {
            return $this->json(['error' => 'Неизвестный маркетплейс.'], 400);
        }

        // --- Сохранение файла ---

        $relativePath = sprintf(
            'marketplace/reconciliation/%s/%s/%s.xlsx',
            $marketplace,
            $dateFrom->format('Y-m'),
            Uuid::uuid4()->toString(),
        );
        $stored = $this->storageService->storeUploadedFile($file, $relativePath);

        // --- Создание сессии ---

        $session = new ReconciliationSession(
            $companyId,
            $marketplace,
            $dateFrom,
            $dateTo,
            $stored['originalFilename'],
            $stored['storagePath'],
        );

        $this->em->persist($session);
        $this->em->flush();

        // --- Запуск сверки ---

        try {
            ($this->reconciliationAction)($companyId, $session);
        } catch (\Throwable) {
            // Action уже пометил сессию как failed и сделал flush
        }

        // --- Ответ ---

        $session = $this->em->find(ReconciliationSession::class, $session->getId());

        if ($session->getStatus()->isPending()) {
            return $this->json(['error' => 'Unexpected state: session still pending.'], 500);
        }

        if ($session->getStatus() === \App\Marketplace\Enum\ReconciliationSessionStatus::FAILED) {
            return $this->json([
                'id'           => $session->getId(),
                'status'       => $session->getStatus()->value,
                'errorMessage' => $session->getErrorMessage(),
            ], 422);
        }

        return $this->json([
            'id'     => $session->getId(),
            'status' => $session->getStatus()->value,
            'result' => $session->getDecodedResult(),
        ]);
    }
}

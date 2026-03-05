<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Finance\Enum\PLDocumentStream;
use App\Marketplace\Application\Command\GeneratePLCommand;
use App\Marketplace\Application\GeneratePLFromMarketplaceAction;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\CompanyContextService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Генерация ОПиУ из данных маркетплейса.
 *
 * Тонкий контроллер:
 *   1. Получает company через CompanyContextService (ТОЛЬКО здесь)
 *   2. Создаёт Command с scalar companyId
 *   3. Делегирует в Application Action
 */
#[Route('/marketplace/pl')]
#[IsGranted('ROLE_USER')]
final class MarketplacePLController extends AbstractController
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly GeneratePLFromMarketplaceAction $generateAction,
    ) {
    }

    /**
     * Синхронная генерация ОПиУ из UI.
     *
     * POST /marketplace/pl/generate
     * Params: marketplace, stream (или all_streams), period_from, period_to
     */
    #[Route('/generate', name: 'marketplace_pl_generate', methods: ['POST'])]
    public function generate(Request $request): Response
    {
        $company = $this->companyContext->getCompany();
        $user = $this->getUser();

        $marketplace = $request->request->get('marketplace', '');
        $periodFrom = $request->request->get('period_from', '');
        $periodTo = $request->request->get('period_to', '');
        $allStreams = $request->request->getBoolean('all_streams');
        $streamValue = $request->request->get('stream', '');

        // Валидация
        if (!MarketplaceType::tryFrom($marketplace)) {
            $this->addFlash('error', 'Неизвестный маркетплейс: ' . $marketplace);

            return $this->redirectToRoute('marketplace_index');
        }

        if (!$periodFrom || !$periodTo) {
            $this->addFlash('error', 'Укажите период');

            return $this->redirectToRoute('marketplace_index');
        }

        // Определяем потоки
        $streams = $allStreams
            ? [PLDocumentStream::REVENUE, PLDocumentStream::COSTS]
            : [PLDocumentStream::from($streamValue)];

        $totalDocuments = 0;
        $errors = [];

        foreach ($streams as $stream) {
            try {
                $command = new GeneratePLCommand(
                    companyId: (string) $company->getId(),
                    marketplace: $marketplace,
                    stream: $stream->value,
                    periodFrom: $periodFrom,
                    periodTo: $periodTo,
                    actorUserId: (string) $user->getId(),
                );

                $documentId = ($this->generateAction)($command);

                if ($documentId) {
                    ++$totalDocuments;
                }
            } catch (\Throwable $e) {
                $errors[] = $stream->getDisplayName() . ': ' . $e->getMessage();
            }
        }

        if ($errors) {
            foreach ($errors as $error) {
                $this->addFlash('error', 'Ошибка генерации ОПиУ — ' . $error);
            }
        }

        if ($totalDocuments > 0) {
            $this->addFlash('success', sprintf(
                'ОПиУ сгенерирован! Создано документов: %d (%s, %s – %s)',
                $totalDocuments,
                MarketplaceType::from($marketplace)->getDisplayName(),
                $periodFrom,
                $periodTo,
            ));
        } elseif (empty($errors)) {
            $this->addFlash('warning', 'Нет необработанных данных за указанный период');
        }

        return $this->redirectToRoute('marketplace_index');
    }
}

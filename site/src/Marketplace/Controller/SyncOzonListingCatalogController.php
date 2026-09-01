<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Marketplace\Message\SyncOzonListingCatalogMessage;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ручной запуск загрузки каталога Ozon «по запросу».
 *
 * Только HTTP in/out: компания берётся из активной сессии, а не из запроса,
 * дальше всё делает async-обработчик. Взаимное исключение одновременных
 * прогонов — на нём же (блокировка по company + connection): проверка «уже
 * идёт» в контроллере была бы гонкой.
 */
#[Route('/marketplace/listings/sync-ozon-catalog', name: 'marketplace_listings_sync_ozon_catalog', methods: ['POST'])]
#[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
final class SyncOzonListingCatalogController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        if (!$this->isCsrfTokenValid('marketplace_listings_sync_ozon_catalog', (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }

        $connections = $this->marketplaceFacade->getActiveOzonSellerConnections($companyId);

        if ([] === $connections) {
            $this->addFlash('warning', 'Нет активного подключения Ozon. Добавьте его в разделе «Подключения».');

            return $this->redirectToRoute('marketplace_listings_index');
        }

        foreach ($connections as $connection) {
            $this->messageBus->dispatch(new SyncOzonListingCatalogMessage(
                companyId: $companyId,
                connectionId: $connection['connectionId'],
            ));
        }

        $this->addFlash(
            'success',
            'Загрузка каталога Ozon запущена. Наименования и артикулы обновятся в течение нескольких минут.',
        );

        return $this->redirectToRoute('marketplace_listings_index');
    }
}

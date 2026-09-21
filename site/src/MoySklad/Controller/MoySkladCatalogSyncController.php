<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Security\ModuleAccess;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Message\SyncCatalogMessage;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
final class MoySkladCatalogSyncController extends AbstractController
{
    #[Route('/moy-sklad/connections/{id}/sync-catalog', name: 'moysklad_connections_sync_catalog', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function __invoke(string $id, Request $request, ActiveCompanyService $activeCompany, MoySkladConnectionWriteRepository $connections, MessageBusInterface $bus): Response
    {
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $connection = $connections->findByIdAndCompanyId($id, $companyId);
        if (null === $connection) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('moysklad_sync_catalog'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if (!$connection->isActive() || ConnectionCheckStatus::CONNECTED !== $connection->getCheckStatus() || null === $connection->getAccountId()) {
            $this->addFlash('error', 'Проверьте и включите подключение перед загрузкой товаров.');

            return $this->redirectToRoute('moysklad_connections_index');
        }

        $bus->dispatch(new SyncCatalogMessage($companyId, $id));
        $this->addFlash('success', 'Запрос поставлен в очередь. Если загрузка уже выполняется, повторный запрос будет пропущен.');

        return $this->redirectToRoute('moysklad_connections_index');
    }
}

<?php

declare(strict_types=1);

namespace App\BankSync\Controller;

use App\BankSync\Message\SyncBankConnectionMessage;
use App\BankSync\Repository\BankConnectionRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ConnectionEnqueueSyncController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BankConnectionRepository $connections,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/bank-connections/{id}/enqueue-import', name: 'bank_sync_connection_enqueue_sync', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request, string $id): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $connection = $this->connections->findOneByIdAndCompanyId($id, $companyId)
            ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('enqueue_import_'.$connection->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->bus->dispatch(new SyncBankConnectionMessage($companyId, $connection->getId()));
        $this->addFlash('success', 'Импорт поставлен в очередь');

        return $this->redirectToRoute('bank_sync_connection_index');
    }
}

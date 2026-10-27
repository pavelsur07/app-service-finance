<?php

declare(strict_types=1);

namespace App\BankSync\Controller;

use App\BankSync\Application\DeleteBankConnectionAction;
use App\BankSync\Exception\BankConnectionBusyException;
use App\BankSync\Exception\BankConnectionNotFoundException;
use App\BankSync\Repository\BankConnectionRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ConnectionDeleteController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BankConnectionRepository $connections,
        private readonly DeleteBankConnectionAction $deleteConnection,
    ) {
    }

    #[Route('/bank-connections/{id}/delete', name: 'bank_sync_connection_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request, string $id): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        // Чужое подключение — 404 до проверки CSRF: ответ не должен зависеть от токена.
        $connection = $this->connections->findOneByIdAndCompanyId($id, $companyId)
            ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('delete'.$connection->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            ($this->deleteConnection)($companyId, $connection->getId());
            $this->addFlash('success', 'Подключение удалено.');
        } catch (BankConnectionBusyException) {
            $this->addFlash('warning', 'Подключение сейчас обновляется. Повторите удаление через минуту.');
        } catch (BankConnectionNotFoundException) {
            // Удалили параллельно — результат тот же.
        }

        return $this->redirectToRoute('bank_sync_connection_index');
    }
}

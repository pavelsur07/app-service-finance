<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transfer;

use App\Cash\Facade\CashFacade;
use App\Cash\Repository\Transfer\CashTransferRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/cash-transfers')]
final class CashTransferDeleteController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashTransferRepository $repository,
        private readonly CashFacade $cashFacade,
        private readonly AuditContextProvider $auditContextProvider,
    ) {
    }

    #[Route('/{id}/delete', name: 'cash_transfer_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(string $id, Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        if (null === $this->repository->findOneByIdAndCompanyId($id, (string) $company->getId())) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('cash_transfer_delete'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF токен.');

            return $this->redirectToRoute('cash_transfer_show', ['id' => $id]);
        }
        try {
            $this->cashFacade->deleteTransfer((string) $company->getId(), $id, $this->auditContextProvider->getActorUserId());
            $this->addFlash('success', 'Перевод удалён вместе с обеими операциями.');
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('cash_transfer_show', ['id' => $id]);
        }

        return $this->redirectToRoute('cash_transfer_deleted_index');
    }
}

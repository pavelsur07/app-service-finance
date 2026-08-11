<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transfer;

use App\Cash\Application\DTO\CreateCashTransferCommand;
use App\Cash\DTO\CashTransferFormData;
use App\Cash\Facade\CashFacade;
use App\Cash\Form\Transfer\CashTransferType;
use App\Cash\Repository\Transfer\CashTransferRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/cash-transfers')]
final class CashTransferController extends AbstractController
{
    public function __construct(private readonly ActiveCompanyService $companyService)
    {
    }

    #[Route('/new', name: 'cash_transfer_new', methods: ['GET', 'POST'])]
    public function new(Request $request, CashFacade $cashFacade): Response
    {
        // Один экшен на GET и POST: read покрыт ModuleAccessSubscriber, write гейтим здесь.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $company = $this->companyService->getActiveCompany();
        $data = new CashTransferFormData(Uuid::uuid7()->toString());
        $form = $this->createForm(CashTransferType::class, $data, ['company' => $company]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $result = $cashFacade->createTransfer(new CreateCashTransferCommand(
                    $company->getId(),
                    (string) $data->sourceAccount?->getId(),
                    (string) $data->targetAccount?->getId(),
                    $data->normalizedSourceAmount(),
                    $data->normalizedTargetAmount(),
                    $data->requiredOccurredAt(),
                    $data->idempotencyKey,
                    $data->description,
                ));
                $this->addFlash('success', $result->duplicate ? 'Перевод уже был создан.' : 'Перевод создан.');

                return $this->redirectToRoute('cash_transfer_show', ['id' => $result->transferId]);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('cash/transfer/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/deleted', name: 'cash_transfer_deleted_index', methods: ['GET'])]
    public function deletedIndex(Request $request, CashTransferRepository $repository): Response
    {
        $company = $this->companyService->getActiveCompany();
        $pager = $repository->paginateDeletedByCompanyId(
            $company->getId(),
            max(1, $request->query->getInt('page', 1)),
            50,
        );

        return $this->render('cash/transfer/deleted_index.html.twig', [
            'transfers' => iterator_to_array($pager->getCurrentPageResults()),
            'pager' => $pager,
        ]);
    }

    #[Route('/{id}', name: 'cash_transfer_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id, CashTransferRepository $repository): Response
    {
        $transfer = $repository->findOneDetailedByIdAndCompanyId(
            $id,
            $this->companyService->getActiveCompany()->getId(),
        ) ?? throw $this->createNotFoundException();

        return $this->render('cash/transfer/show.html.twig', ['transfer' => $transfer]);
    }

    #[Route('/{id}/delete', name: 'cash_transfer_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function delete(
        string $id,
        Request $request,
        CashFacade $cashFacade,
        CashTransferRepository $repository,
        AuditContextProvider $auditContextProvider,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        if (null === $repository->findOneByIdAndCompanyId($id, $company->getId())) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('cash_transfer_delete'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF токен.');

            return $this->redirectToRoute('cash_transfer_show', ['id' => $id]);
        }

        try {
            $cashFacade->deleteTransfer($company->getId(), $id, $auditContextProvider->getActorUserId());
            $this->addFlash('success', 'Перевод удалён вместе с обеими операциями.');
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('cash_transfer_show', ['id' => $id]);
        }

        return $this->redirectToRoute('cash_transfer_deleted_index');
    }

    #[Route('/{id}/restore', name: 'cash_transfer_restore', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function restore(
        string $id,
        Request $request,
        CashFacade $cashFacade,
        CashTransferRepository $repository,
        AuditContextProvider $auditContextProvider,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        if (null === $repository->findOneByIdAndCompanyId($id, $company->getId())) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('cash_transfer_restore'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF токен.');

            return $this->redirectToRoute('cash_transfer_deleted_index');
        }

        try {
            $cashFacade->restoreTransfer($company->getId(), $id, $auditContextProvider->getActorUserId());
            $this->addFlash('success', 'Перевод восстановлен вместе с обеими операциями.');

            return $this->redirectToRoute('cash_transfer_show', ['id' => $id]);
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('cash_transfer_deleted_index');
    }
}

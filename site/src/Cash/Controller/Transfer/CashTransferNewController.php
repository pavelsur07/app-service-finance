<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transfer;

use App\Cash\Application\DTO\CashTransferFormData;
use App\Cash\Application\DTO\CreateCashTransferCommand;
use App\Cash\Facade\CashFacade;
use App\Cash\Form\Transfer\CashTransferType;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/cash-transfers')]
final class CashTransferNewController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashFacade $cashFacade,
    ) {
    }

    #[Route('/new', name: 'cash_transfer_new', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
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
                $result = $this->cashFacade->createTransfer(new CreateCashTransferCommand((string) $company->getId(), (string) $data->sourceAccount?->getId(), (string) $data->targetAccount?->getId(), $data->normalizedSourceAmount(), $data->normalizedTargetAmount(), $data->requiredOccurredAt(), $data->idempotencyKey, $data->description));
                $this->addFlash('success', $result->duplicate ? 'Перевод уже был создан.' : 'Перевод создан.');

                return $this->redirectToRoute('cash_transfer_show', ['id' => $result->transferId]);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('cash/transfer/new.html.twig', ['form' => $form->createView()]);
    }
}

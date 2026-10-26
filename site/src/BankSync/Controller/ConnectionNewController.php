<?php

declare(strict_types=1);

namespace App\BankSync\Controller;

use App\BankSync\Application\DTO\BankConnectionFormData;
use App\BankSync\Application\SaveBankConnectionAction;
use App\BankSync\Exception\BankTokenRequiredException;
use App\BankSync\Form\BankConnectionType;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ConnectionNewController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly SaveBankConnectionAction $saveConnection,
    ) {
    }

    #[Route('/bank-connections/new', name: 'bank_sync_connection_new', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $data = new BankConnectionFormData();
        $form = $this->createForm(BankConnectionType::class, $data, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->saveConnection)((string) $company->getId(), $data);

                return $this->redirectToRoute('bank_sync_connection_index');
            } catch (BankTokenRequiredException) {
                $form->get('token')->addError(new FormError('Укажите API ключ.'));
            }
        }

        return $this->render('@BankSync/connection/new.html.twig', [
            'form' => $form,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\BankSync\Controller;

use App\BankSync\Application\DTO\BankConnectionFormData;
use App\BankSync\Application\SaveBankConnectionAction;
use App\BankSync\Exception\BankConnectionBusyException;
use App\BankSync\Exception\BankStaticTokenNotSupportedException;
use App\BankSync\Form\BankConnectionType;
use App\BankSync\Repository\BankConnectionRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ConnectionEditController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BankConnectionRepository $connections,
        private readonly SaveBankConnectionAction $saveConnection,
    ) {
    }

    #[Route('/bank-connections/{id}/edit', name: 'bank_sync_connection_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $connection = $this->connections->findOneByIdAndCompanyId($id, $companyId)
            ?? throw $this->createNotFoundException();

        $data = BankConnectionFormData::fromConnection($connection);
        $form = $this->createForm(BankConnectionType::class, $data, ['is_new' => false, 'static_token' => $connection->getBankCode()->supportsStaticToken()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->saveConnection)($companyId, $data, $connection->getId());

                return $this->redirectToRoute('bank_sync_connection_index');
            } catch (BankConnectionBusyException) {
                $form->addError(new FormError('Подключение сейчас обновляется. Сохраните ещё раз через минуту.'));
            } catch (BankStaticTokenNotSupportedException) {
                $form->addError(new FormError('Ключ и среду этого банка меняет только новый вход через банк.'));
            }
        }

        return $this->render('@BankSync/connection/edit.html.twig', [
            'form' => $form,
            'connection' => $connection,
        ]);
    }
}

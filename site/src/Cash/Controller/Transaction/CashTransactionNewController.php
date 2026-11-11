<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\AddCashTransactionAction;
use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Form\Transaction\CashTransactionType;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\Exception\ORMException;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/cash-transactions')]
final class CashTransactionNewController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly AddCashTransactionAction $addTransaction,
    ) {
    }

    /**
     * @throws ORMException
     */
    #[Route('/new', name: 'cash_transaction_new', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $dto = new CashTransactionDTO();
        $dto->occurredAt = new \DateTimeImmutable('today');
        // Ключ повтора выдаётся при показе формы: двойная отправка не создаёт вторую транзакцию.
        $dto->idempotencyKey = Uuid::uuid7()->toString();
        $form = $this->createForm(CashTransactionType::class, $dto, ['company' => $company, 'idempotency_key' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                /** @var CashTransactionDTO $data */
                $data = $form->getData();
                try {
                    ($this->addTransaction)($data);
                    $this->addFlash('success', 'Транзакция добавлена');

                    return $this->redirectToRoute('cash_transaction_index', array_filter($request->query->all(), static fn ($value) => '' !== $value));
                } catch (\DomainException $e) {
                    // Период закрыт — показать ошибку пользователю
                    $form->addError(new FormError($e->getMessage()));
                    $this->addFlash('danger', $e->getMessage());
                    // остаёмся на форме (без редиректа)
                }
            }
        }

        // Невалидная форма — та же страница с ошибками у полей и 422, а не сырой JSON.
        return $this->render('transaction/new.html.twig', ['form' => $form->createView(), 'tx' => null], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}

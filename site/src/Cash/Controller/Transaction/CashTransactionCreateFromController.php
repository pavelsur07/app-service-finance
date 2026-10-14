<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\CreateDocumentFromTransactionAction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CashTransactionCreateFromController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CreateDocumentFromTransactionAction $action,
        private readonly CashTransactionRepository $transactions,
    ) {
    }

    #[Route(
        '/finance/cash-transactions/{id}/create-from',
        name: 'cash_transaction_create_from',
        requirements: ['id' => Requirement::UUID],
        methods: ['POST']
    )]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        // Чужая и несуществующая транзакция неразличимы: 404 не раскрывает,
        // что транзакция с таким id есть у другой компании.
        $tx = $this->transactions->findOneByIdAndCompanyId($id, (string) $company->getId());
        if (null === $tx) {
            return new JsonResponse(['error' => true, 'message' => 'Транзакция не найдена.'], 404);
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('cash_transaction_create_from', $token)) {
            return new JsonResponse(['error' => true, 'message' => 'Неверный CSRF-токен.'], 403);
        }

        $confirmed = filter_var($request->request->get('confirmed', false), \FILTER_VALIDATE_BOOLEAN);

        try {
            $result = ($this->action)($tx, $confirmed);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => true, 'message' => $e->getMessage()], 422);
        }

        if ($result->needsConfirmation) {
            return new JsonResponse([
                'needsConfirmation' => true,
                'warningMessage' => $result->warningMessage,
            ]);
        }

        if ($result->hasViolation && null !== $result->documentId) {
            return new JsonResponse([
                'redirect' => $this->generateUrl('document_edit', ['id' => $result->documentId]),
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'flash' => 'Документ ОПиУ создан.',
        ]);
    }
}

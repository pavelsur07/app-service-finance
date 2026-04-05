<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\CreateDocumentFromTransactionAction;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CashTransactionCreateFromController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CreateDocumentFromTransactionAction $action,
    ) {
    }

    #[Route(
        '/finance/cash-transactions/{id}/create-from',
        name: 'cash_transaction_create_from',
        methods: ['POST']
    )]
    public function __invoke(Request $request, CashTransaction $tx): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        if ($tx->getCompany()->getId() !== $company->getId()) {
            return new JsonResponse(['error' => true, 'message' => 'Доступ запрещён.'], 403);
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('cash_transaction_create_from', $token)) {
            return new JsonResponse(['error' => true, 'message' => 'Неверный CSRF-токен.'], 403);
        }

        $confirmed = (bool) $request->request->get('confirmed', false);

        try {
            $result = ($this->action)($tx, $confirmed);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => true, 'message' => $e->getMessage()], 422);
        }

        if ($result->needsConfirmation) {
            return new JsonResponse([
                'needsConfirmation' => true,
                'warningMessage'    => $result->warningMessage,
            ]);
        }

        if ($result->hasViolation) {
            return new JsonResponse([
                'redirect' => $this->generateUrl('document_edit', ['id' => $result->documentId]),
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'flash'   => 'Документ ОПиУ создан.',
        ]);
    }
}

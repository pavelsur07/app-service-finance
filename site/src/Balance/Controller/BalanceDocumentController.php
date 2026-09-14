<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Application\BalanceLedgerService;
use App\Balance\Domain\Policy\LedgerAmount;
use App\Balance\Form\BalanceDocumentType;
use App\Balance\Infrastructure\Query\LedgerQuery;
use App\Balance\Security\BalanceAccess;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalanceDocumentController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly LedgerQuery $query,
        private readonly BalanceLedgerService $ledger,
    ) {
    }

    #[Route('/balance/documents/new', name: 'balance_document_new', methods: ['GET', 'POST'], priority: 10)]
    #[Route('/balance/documents/{id}', name: 'balance_document', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, ?string $id = null): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $this->access->actor($companyId);
        $document = null === $id ? null : $this->query->document($companyId, $id);
        $book = $this->query->book($companyId);
        $permissions = $this->access->permissions($companyId);
        if (null !== $document && ('posted' === $document['status'] || !$permissions['prepare'])) {
            if ($request->isMethod('POST')) {
                throw $this->createAccessDeniedException('Проведенный документ неизменяем.');
            }

            return $this->render('balance/document_view.html.twig', ['document' => $document, 'permissions' => $this->access->permissions($companyId), 'book' => $this->query->book($companyId)]);
        }
        $actor = $this->access->actor($companyId, 'prepare');
        if (null === $book || null === $book['currency']) {
            return $this->redirectToRoute('balance_setup');
        }
        $choices = [];
        $attributes = [];
        foreach ($this->query->accounts($companyId) as $account) {
            $label = $account['name'].' ['.$account['code'].']'.($account['is_archived'] ? ' — архив' : '');
            $choices[$label] = $account['id'];
            $attributes[$label] = ['data-side' => $account['type']];
        }
        $lines = [];
        foreach ($document['lines'] ?? [] as $line) {
            $lines[] = ['accountId' => $line['account_id'], 'direction' => $line['direction'], 'amount' => LedgerAmount::decimal((string) $line['amount'], (string) $book['currency'])];
        }
        $form = $this->createForm(BalanceDocumentType::class, ['kind' => $document['kind'] ?? ($book['initialized'] ? 'operation' : 'opening'), 'date' => $document['operation_date'] ?? ($book['initialized'] ? (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d') : $book['start_date']), 'reason' => $document['reason'] ?? '', 'requestKey' => $document['request_key'] ?? Uuid::uuid7()->toString(), 'version' => $document['version'] ?? '', 'lines' => $lines], ['account_choices' => $choices, 'account_attributes' => $attributes]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{kind:string,date:string,reason:string,requestKey:string,version:string|int,lines:array<int,array{accountId:string,direction:string,amount:string}>} $data */
            $data = $form->getData();
            $saved = $this->ledger->saveDraft($companyId, $actor, $data['requestKey'], $data['kind'], $data['date'], $data['reason'], array_values($data['lines']), $id, null === $id ? null : (int) $data['version']);

            return $this->redirectToRoute('balance_document', ['id' => $saved]);
        }

        return $this->render('balance/document_edit.html.twig', ['form' => $form->createView(), 'document' => $document, 'permissions' => $this->access->permissions($companyId), 'book' => $this->query->book($companyId)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Application\BalanceLedgerService;
use App\Balance\Domain\Policy\LedgerAmount;
use App\Balance\Form\BalanceTargetType;
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
final class BalanceTargetController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly LedgerQuery $query,
        private readonly BalanceLedgerService $ledger,
    ) {
    }

    #[Route('/balance/accounts/{id}/target', name: 'balance_account_target', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $actor = $this->access->actor($companyId, 'prepare');
        $account = $this->query->account($companyId, $id);
        $book = $this->query->book($companyId);
        if (null === $book || null === $book['currency']) {
            return $this->redirectToRoute('balance_setup');
        }
        $documentId = $request->query->getString('document');
        $document = '' === $documentId ? null : $this->query->document($companyId, $documentId);
        $preparation = $document['target_preparation'] ?? null;
        if (null !== $document && ('draft' !== $document['status'] || !is_array($preparation) || $preparation['accountId'] !== $id)) {
            throw $this->createNotFoundException('Корректировка этого счета не найдена.');
        }
        $lines = [];
        foreach ($document['lines'] ?? [] as $line) {
            if ($line['account_id'] !== $id) {
                $lines[] = ['accountId' => $line['account_id'], 'direction' => $line['direction'], 'amount' => LedgerAmount::decimal((string) $line['amount'], (string) $book['currency'])];
            }
        }
        $choices = [];
        $attributes = [];
        foreach ($this->query->accounts($companyId) as $choiceAccount) {
            $label = $choiceAccount['name'].' ['.$choiceAccount['code'].']'.($choiceAccount['is_archived'] ? ' — архив' : '');
            $choices[$label] = $choiceAccount['id'];
            $attributes[$label] = ['data-side' => $choiceAccount['type']];
        }
        $form = $this->createForm(BalanceTargetType::class, ['date' => $preparation['date'] ?? (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d'), 'target' => LedgerAmount::decimal((string) ($preparation['target'] ?? $account['balance']), (string) $book['currency']), 'reason' => $document['reason'] ?? '', 'requestKey' => $document['request_key'] ?? Uuid::uuid7()->toString(), 'journalVersion' => $book['version'], 'documentVersion' => $document['version'] ?? '', 'lines' => $lines], ['account_choices' => $choices, 'account_attributes' => $attributes]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{date:string,target:string,reason:string,requestKey:string,journalVersion:string|int,documentVersion:string|int,lines:array<int,array{accountId:string,direction:string,amount:string}>} $data */
            $data = $form->getData();
            $saved = $this->ledger->prepareTargetCorrection($companyId, $actor, $data['requestKey'], $id, $data['date'], $data['target'], $data['reason'], (int) $data['journalVersion'], array_values($data['lines']), null === $document ? null : $documentId, null === $document ? null : (int) $data['documentVersion']);

            return $this->redirectToRoute('balance_document', ['id' => $saved]);
        }

        return $this->render('balance/target.html.twig', ['form' => $form->createView(), 'account' => $account, 'document' => $document, 'permissions' => $this->access->permissions($companyId), 'book' => $this->query->book($companyId)]);
    }
}

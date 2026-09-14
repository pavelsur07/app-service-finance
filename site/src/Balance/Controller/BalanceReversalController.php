<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Application\BalanceLedgerService;
use App\Balance\Form\BalanceReversalType;
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
final class BalanceReversalController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly LedgerQuery $query,
        private readonly BalanceLedgerService $ledger,
    ) {
    }

    #[Route('/balance/documents/{id}/reverse', name: 'balance_document_reverse', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $actor = $this->access->actor($companyId, 'post');
        $document = $this->query->document($companyId, $id);
        $form = $this->createForm(BalanceReversalType::class, ['date' => (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d'), 'reason' => '', 'requestKey' => Uuid::uuid7()->toString()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{date:string,reason:string,requestKey:string} $data */
            $data = $form->getData();
            $reversal = $this->ledger->reverse($companyId, $actor, $id, $data['date'], $data['reason'], $data['requestKey']);

            return $this->redirectToRoute('balance_document', ['id' => $reversal]);
        }

        return $this->render('balance/reversal.html.twig', ['form' => $form->createView(), 'document' => $document, 'permissions' => $this->access->permissions($companyId), 'book' => $this->query->book($companyId)]);
    }
}

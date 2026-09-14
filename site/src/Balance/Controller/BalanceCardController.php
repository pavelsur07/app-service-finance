<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Infrastructure\Query\LedgerQuery;
use App\Balance\Security\BalanceAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalanceCardController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly LedgerQuery $query,
    ) {
    }

    #[Route('/balance/accounts/{id}/card', name: 'balance_account_card', defaults: ['article' => false], methods: ['GET'])]
    #[Route('/balance/articles/{id}/card', name: 'balance_article_card', defaults: ['article' => true], methods: ['GET'])]
    public function __invoke(Request $request, string $id, bool $article): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $this->access->actor($companyId);
        $from = $request->query->getString('from', (new \DateTimeImmutable('first day of this month', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d'));
        $to = $request->query->getString('to', (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d'));
        $item = $article ? $this->query->category($companyId, $id) : $this->query->account($companyId, $id);
        $card = $article ? $this->query->articleCard($companyId, $id, $from, $to, $request->query->getInt('page', 1), $request->query->getInt('limit', 50)) : $this->query->accountCard($companyId, $id, $from, $to, $request->query->getInt('page', 1), $request->query->getInt('limit', 50));

        return $this->render('balance/card.html.twig', ['card' => $card, 'item' => $item, 'article' => $article, 'from' => $from, 'to' => $to, 'permissions' => $this->access->permissions($companyId), 'book' => $this->query->book($companyId)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/cash-transactions')]
final class CashAutoRuleApplyPageController extends AbstractController
{
    #[Route('/auto-rule-apply', name: 'cash_transaction_auto_rule_apply_page', methods: ['GET'])]
    public function __invoke(): Response
    {
        $today = new \DateTimeImmutable('today');
        $from = $today->setDate((int) $today->format('Y'), 1, 1);

        return $this->render('transaction/auto_rule_apply.html.twig', ['from' => $from, 'to' => $today]);
    }
}

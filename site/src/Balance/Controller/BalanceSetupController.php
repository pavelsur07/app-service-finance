<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Application\BalanceLedgerService;
use App\Balance\Form\BalanceSetupType;
use App\Balance\Infrastructure\Query\LedgerQuery;
use App\Balance\Security\BalanceAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalanceSetupController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly LedgerQuery $query,
        private readonly BalanceLedgerService $ledger,
    ) {
    }

    #[Route('/balance/setup', name: 'balance_setup', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $actor = $this->access->actor($companyId, 'manage');
        $book = $this->query->book($companyId);
        $form = $this->createForm(BalanceSetupType::class, ['currency' => $book['currency'] ?? 'RUB', 'startDate' => $book['start_date'] ?? (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d')], ['disabled' => (bool) ($book['initialized'] ?? false)]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{currency:string,startDate:string} $data */
            $data = $form->getData();
            $this->ledger->configureBook($companyId, $data['currency'], $data['startDate'], $actor);
            $this->addFlash('success', 'Настройки сохранены. Создайте счета и документ начальных остатков.');

            return $this->redirectToRoute('balance_index');
        }

        return $this->render('balance/setup.html.twig', ['form' => $form->createView(), 'permissions' => $this->access->permissions($companyId), 'book' => $this->query->book($companyId)]);
    }
}

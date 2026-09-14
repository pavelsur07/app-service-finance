<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Application\BalancePeriodAction;
use App\Balance\Exception\BalanceLedgerException;
use App\Balance\Form\BalancePeriodType;
use App\Balance\Infrastructure\Query\LedgerQuery;
use App\Balance\Security\BalanceAccess;
use App\Shared\Service\ActiveCompanyService;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalancePeriodsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly LedgerQuery $query,
        private readonly BalancePeriodAction $period,
    ) {
    }

    #[Route('/balance/periods', name: 'balance_periods', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $this->access->actor($companyId);
        $permissions = $this->access->permissions($companyId);
        if ($request->isMethod('POST') && !$permissions['manage_periods'] && !$permissions['reopen_periods']) {
            throw $this->createAccessDeniedException();
        }
        $actions = [];
        if ($permissions['manage_periods']) {
            $actions['Закрыть'] = '1';
        }
        if ($permissions['reopen_periods']) {
            $actions['Переоткрыть'] = '0';
        }
        $form = $this->createForm(BalancePeriodType::class, ['month' => (new \DateTimeImmutable('first day of last month', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d'), 'close' => $permissions['manage_periods'] ? '1' : '0', 'reason' => ''], ['disabled' => [] === $actions, 'action_choices' => $actions]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{month:string,close:string,reason:string} $data */
            $data = $form->getData();
            $actor = $this->access->actor($companyId, '1' === $data['close'] ? 'manage_periods' : 'reopen_periods');
            $result = ($this->period)($companyId, $actor, $data['month'], '1' === $data['close'], $data['reason']);
            $this->addFlash('success', 'Период обновлен. Непроведенных черновиков: '.$result['drafts']);

            return $this->redirectToRoute('balance_periods');
        }
        $limit = $request->query->getInt('limit', 50);
        if ($limit < 1 || $limit > 200) {
            throw new BalanceLedgerException('Количество строк должно быть от 1 до 200.');
        }
        $pager = new Pagerfanta(new ArrayAdapter($this->query->periods($companyId)));
        $pager->setMaxPerPage($limit);
        $page = $request->query->getInt('page', 1);
        if ($page < 1 || $page > $pager->getNbPages()) {
            throw new BalanceLedgerException('Запрошенная страница не существует.');
        }
        $pager->setCurrentPage($page);

        return $this->render('balance/periods.html.twig', ['form' => $form->createView(), 'items' => $pager->getCurrentPageResults(), 'pager' => $pager, 'permissions' => $this->access->permissions($companyId), 'book' => $this->query->book($companyId)]);
    }
}

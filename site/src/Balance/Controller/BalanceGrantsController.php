<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Application\GrantBalanceAccessAction;
use App\Balance\Exception\BalanceLedgerException;
use App\Balance\Form\BalanceGrantType;
use App\Balance\Infrastructure\Query\LedgerQuery;
use App\Balance\Security\BalanceAccess;
use App\Company\Facade\CompanyFacade;
use App\Shared\Service\ActiveCompanyService;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalanceGrantsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly LedgerQuery $query,
        private readonly GrantBalanceAccessAction $grant,
        private readonly CompanyFacade $companies,
    ) {
    }

    #[Route('/balance/access', name: 'balance_access', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $actor = $this->access->actor($companyId, 'manage');
        $choices = [];
        foreach ($this->companies->listMembers($companyId) as $member) {
            $choices[$member['label']] = $member['id'];
        }
        $form = $this->createForm(BalanceGrantType::class, null, ['user_choices' => $choices]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{userId:string,prepare:bool,post:bool,managePeriods:bool,reopenPeriods:bool} $data */
            $data = $form->getData();
            ($this->grant)($companyId, $actor, $data['userId'], $data['prepare'], $data['post'], $data['managePeriods'], $data['reopenPeriods']);

            return $this->redirectToRoute('balance_access');
        }
        $limit = $request->query->getInt('limit', 50);
        if ($limit < 1 || $limit > 200) {
            throw new BalanceLedgerException('Количество строк должно быть от 1 до 200.');
        }
        $pager = new Pagerfanta(new ArrayAdapter($this->query->grants($companyId)));
        $pager->setMaxPerPage($limit);
        $page = $request->query->getInt('page', 1);
        if ($page < 1 || $page > $pager->getNbPages()) {
            throw new BalanceLedgerException('Запрошенная страница не существует.');
        }
        $pager->setCurrentPage($page);

        return $this->render('balance/access.html.twig', ['form' => $form->createView(), 'items' => $pager->getCurrentPageResults(), 'pager' => $pager, 'members' => array_flip($choices), 'permissions' => $this->access->permissions($companyId), 'book' => $this->query->book($companyId)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Application\BalanceStructureService;
use App\Balance\Form\BalanceAccountType;
use App\Balance\Infrastructure\Query\LedgerQuery;
use App\Balance\Security\BalanceAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalanceAccountEditController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly LedgerQuery $query,
        private readonly BalanceStructureService $structure,
    ) {
    }

    #[Route('/balance/accounts/new', name: 'balance_account_new', methods: ['GET', 'POST'])]
    #[Route('/balance/accounts/{id}/edit', name: 'balance_account_edit', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, ?string $id = null): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $actor = $this->access->actor($companyId, 'manage');
        $item = null === $id ? null : $this->query->account($companyId, $id);
        $choices = [];
        foreach ($this->query->categories($companyId) as $category) {
            if ('article' === $category['kind'] && (!$category['is_archived'] || $category['id'] === ($item['article_id'] ?? null))) {
                $label = (string) $category['path_name'];
                if (isset($choices[$label])) {
                    $label .= ' ('.(count($choices) + 1).')';
                }
                $choices[$label] = $category['id'];
            }
        }
        $form = $this->createForm(BalanceAccountType::class, ['articleId' => $item['article_id'] ?? null, 'name' => $item['name'] ?? '', 'code' => $item['code'] ?? '', 'allowNegative' => $item['allow_negative'] ?? false], ['article_choices' => $choices]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{articleId:string,name:string,code:string,allowNegative:bool} $data */
            $data = $form->getData();
            $this->structure->saveAccount($companyId, $actor, $data['articleId'], $data['name'], $data['code'], $data['allowNegative'], $id);

            return $this->redirectToRoute('balance_accounts');
        }

        return $this->render('balance/account_edit.html.twig', ['form' => $form->createView(), 'item' => $item, 'permissions' => $this->access->permissions($companyId), 'book' => $this->query->book($companyId)]);
    }
}

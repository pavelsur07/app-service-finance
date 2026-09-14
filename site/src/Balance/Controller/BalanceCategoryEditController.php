<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Application\BalanceStructureService;
use App\Balance\Enum\BalanceCategoryType;
use App\Balance\Form\BalanceCategoryFormType;
use App\Balance\Infrastructure\Query\LedgerQuery;
use App\Balance\Security\BalanceAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalanceCategoryEditController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly LedgerQuery $query,
        private readonly BalanceStructureService $structure,
    ) {
    }

    #[Route('/balance/structure/new', name: 'balance_structure_new', methods: ['GET', 'POST'])]
    #[Route('/balance/structure/{id}/edit', name: 'balance_structure_edit', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, ?string $id = null): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $actor = $this->access->actor($companyId, 'manage');
        $item = null === $id ? null : $this->query->category($companyId, $id);
        $choices = [];
        $excluded = null === $id ? [] : [$id => true];
        $categories = $this->query->categories($companyId);
        foreach ($categories as $category) {
            if (isset($excluded[$category['parent_id'] ?? ''])) {
                $excluded[$category['id']] = true;
            }
        }
        foreach ($categories as $category) {
            if ('group' === $category['kind'] && !isset($excluded[$category['id']]) && !$category['is_archived']) {
                $label = (string) $category['path_name'];
                if (isset($choices[$label])) {
                    $label .= ' ('.(count($choices) + 1).')';
                }
                $choices[$label] = $category['id'];
            }
        }
        $form = $this->createForm(BalanceCategoryFormType::class, ['name' => $item['name'] ?? '', 'type' => BalanceCategoryType::from($item['type'] ?? 'asset'), 'kind' => $item['kind'] ?? 'group', 'parentId' => $item['parent_id'] ?? null, 'code' => $item['code'] ?? null, 'isVisible' => $item['is_visible'] ?? true], ['parent_choices' => $choices]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name:string,type:BalanceCategoryType,kind:string,parentId:?string,code:?string,isVisible:bool} $data */
            $data = $form->getData();
            $this->structure->saveCategory($companyId, $actor, $data['name'], $data['type'], $data['parentId'], $data['code'], $data['kind'], $id, $data['isVisible']);

            return $this->redirectToRoute('balance_structure_index');
        }

        return $this->render('balance_structure/edit.html.twig', ['form' => $form->createView(), 'item' => $item, 'permissions' => $this->access->permissions($companyId), 'book' => $this->query->book($companyId)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Application\CreateBalanceCategoryAction;
use App\Balance\Application\DeleteBalanceCategoryAction;
use App\Balance\Application\DTO\CreateBalanceCategoryCommand;
use App\Balance\Application\DTO\LinkBalanceCategoryCommand;
use App\Balance\Application\DTO\UpdateBalanceCategoryCommand;
use App\Balance\Application\LinkBalanceCategoryAction;
use App\Balance\Application\MoveBalanceCategoryAction;
use App\Balance\Application\UpdateBalanceCategoryAction;
use App\Balance\Entity\BalanceCategory;
use App\Balance\Enum\BalanceCategoryType;
use App\Balance\Enum\BalanceLinkSourceType;
use App\Balance\Exception\BalanceCategoryNotFoundException;
use App\Balance\Facade\BalanceFacade;
use App\Balance\Form\BalanceCategoryFormType;
use App\Balance\Repository\BalanceCategoryLinkRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/balance/structure')]
final class BalanceStructureController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BalanceCategoryLinkRepository $balanceCategoryLinkRepository,
        private readonly BalanceFacade $balanceFacade,
        private readonly CreateBalanceCategoryAction $createBalanceCategoryAction,
        private readonly UpdateBalanceCategoryAction $updateBalanceCategoryAction,
        private readonly DeleteBalanceCategoryAction $deleteBalanceCategoryAction,
        private readonly MoveBalanceCategoryAction $moveBalanceCategoryAction,
        private readonly LinkBalanceCategoryAction $linkBalanceCategoryAction,
    ) {
    }

    #[Route('/', name: 'balance_structure_index', methods: ['GET'])]
    public function index(): Response
    {
        $companyId = $this->activeCompanyService->getActiveCompany()->getId();
        $items = $this->balanceFacade->getCategoriesForCompany($companyId);

        return $this->render('balance_structure/index.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/new', name: 'balance_structure_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $companyId = $this->activeCompanyService->getActiveCompany()->getId();

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $command = new CreateBalanceCategoryCommand(
            name: '',
            type: BalanceCategoryType::ASSET,
            parentId: null,
            code: null,
            isVisible: true,
        );

        $form = $this->createForm(BalanceCategoryFormType::class, $command, [
            'parent_choices' => $this->balanceFacade->getCategoryChoicesForCompany($companyId),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            ($this->createBalanceCategoryAction)($companyId, $command);

            return $this->redirectToRoute('balance_structure_index');
        }

        return $this->render('balance_structure/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'balance_structure_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, BalanceCategory $category): Response
    {
        $companyId = $this->activeCompanyService->getActiveCompany()->getId();

        if ($category->getCompanyId() !== $companyId) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $command = new UpdateBalanceCategoryCommand(
            id: $category->getId(),
            name: $category->getName(),
            type: $category->getType(),
            parentId: $category->getParent()?->getId(),
            code: $category->getCode(),
            isVisible: $category->isVisible(),
        );

        $excludeIds = array_merge([$category->getId()], $this->collectDescendantIds($category));
        $form = $this->createForm(BalanceCategoryFormType::class, $command, [
            'parent_choices' => $this->balanceFacade->getCategoryChoicesForCompany($companyId, $excludeIds),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            ($this->updateBalanceCategoryAction)($companyId, $command);

            return $this->redirectToRoute('balance_structure_index');
        }

        $links = $this->balanceCategoryLinkRepository->findByCompanyAndCategory($companyId, $category);

        return $this->render('balance_structure/edit.html.twig', [
            'form' => $form->createView(),
            'item' => $category,
            'links' => $links,
        ]);
    }

    #[Route('/{id}/delete', name: 'balance_structure_delete', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function delete(Request $request, BalanceCategory $category): Response
    {
        $companyId = $this->activeCompanyService->getActiveCompany()->getId();

        if ($category->getCompanyId() !== $companyId) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete'.$category->getId(), $request->request->getString('_token'))) {
            ($this->deleteBalanceCategoryAction)($companyId, $category->getId());
        }

        return $this->redirectToRoute('balance_structure_index');
    }

    #[Route('/move', name: 'balance_structure_move', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function move(Request $request): Response
    {
        $companyId = $this->activeCompanyService->getActiveCompany()->getId();
        $categoryId = $request->request->getString('category_id');
        $direction = $request->request->getString('direction');

        if (!$this->isCsrfTokenValid('move'.$categoryId, $request->request->getString('_token'))) {
            return $this->redirectToRoute('balance_structure_index');
        }

        try {
            ($this->moveBalanceCategoryAction)($companyId, $categoryId, $direction);
        } catch (BalanceCategoryNotFoundException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute('balance_structure_index');
    }

    #[Route('/{id}/link-money-accounts-total', name: 'balance_structure_link_money_accounts_total', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function linkMoneyAccountsTotal(Request $request, BalanceCategory $category): Response
    {
        $companyId = $this->activeCompanyService->getActiveCompany()->getId();

        if ($category->getCompanyId() !== $companyId) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('link_money_accounts_total'.$category->getId(), $request->request->getString('_token'))) {
            return $this->redirectToRoute('balance_structure_edit', ['id' => $category->getId()]);
        }

        ($this->linkBalanceCategoryAction)($companyId, new LinkBalanceCategoryCommand(
            categoryId: $category->getId(),
            sourceType: BalanceLinkSourceType::MONEY_ACCOUNTS_TOTAL,
            sourceId: null,
        ));

        return $this->redirectToRoute('balance_structure_edit', ['id' => $category->getId()]);
    }

    #[Route('/{id}/link-money-funds-total', name: 'balance_structure_link_money_funds_total', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function linkMoneyFundsTotal(Request $request, BalanceCategory $category): Response
    {
        $companyId = $this->activeCompanyService->getActiveCompany()->getId();

        if ($category->getCompanyId() !== $companyId) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('link_money_funds_total'.$category->getId(), $request->request->getString('_token'))) {
            return $this->redirectToRoute('balance_structure_edit', ['id' => $category->getId()]);
        }

        ($this->linkBalanceCategoryAction)($companyId, new LinkBalanceCategoryCommand(
            categoryId: $category->getId(),
            sourceType: BalanceLinkSourceType::MONEY_FUNDS_TOTAL,
            sourceId: null,
        ));

        return $this->redirectToRoute('balance_structure_edit', ['id' => $category->getId()]);
    }

    /**
     * @return list<string>
     */
    private function collectDescendantIds(BalanceCategory $category): array
    {
        $ids = [];
        foreach ($category->getChildren() as $child) {
            $ids[] = $child->getId();
            $ids = array_merge($ids, $this->collectDescendantIds($child));
        }

        return $ids;
    }
}

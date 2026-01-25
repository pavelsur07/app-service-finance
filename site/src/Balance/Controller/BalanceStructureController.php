<?php

namespace App\Balance\Controller;

use App\Balance\Entity\BalanceCategory;
use App\Balance\Enum\BalanceLinkSourceType;
use App\Balance\Form\BalanceCategoryFormType;
use App\Balance\Repository\BalanceCategoryLinkRepository;
use App\Balance\Repository\BalanceCategoryRepository;
use App\Balance\Service\BalanceStructureSeeder;
use App\Company\Entity\Company;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/balance/structure')]
class BalanceStructureController extends AbstractController
{
    public function __construct(private readonly BalanceStructureSeeder $balanceStructureSeeder)
    {
    }

    #[Route('/', name: 'balance_structure_index', methods: ['GET'])]
    public function index(BalanceCategoryRepository $repo, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $items = $repo->findRootByCompany($company);

        return $this->render('balance_structure/index.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/new', name: 'balance_structure_new', methods: ['GET', 'POST'])]
    public function new(Request $request, BalanceCategoryRepository $repo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $category = new BalanceCategory(Uuid::uuid4()->toString(), $company);

        $parents = $repo->findTreeByCompany($company);
        $nextSortOrder = $repo->getNextSortOrder($company, $category->getParent());
        $category->setSortOrder($nextSortOrder);

        $form = $this->createForm(BalanceCategoryFormType::class, $category, ['parents' => $parents]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($category->getParent() && $category->getParent()->getLevel() >= 5) {
                $this->addFlash('danger', 'Максимальная вложенность — 5 уровней');
            } else {
                $nextSortOrder = $repo->getNextSortOrder($company, $category->getParent());
                $category->setSortOrder($nextSortOrder);
                $em->persist($category);
                $em->flush();

                return $this->redirectToRoute('balance_structure_index');
            }
        }

        return $this->render('balance_structure/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'balance_structure_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, BalanceCategory $category, BalanceCategoryRepository $repo, BalanceCategoryLinkRepository $linkRepository, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        if ($category->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $parents = $this->buildParentChoices($repo, $company, $category);
        $originalParent = $category->getParent();

        $form = $this->createForm(BalanceCategoryFormType::class, $category, ['parents' => $parents]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($category->getParent() && $category->getParent()->getLevel() >= 5) {
                $this->addFlash('danger', 'Максимальная вложенность — 5 уровней');
            } else {
                if ($category->getParent() !== $originalParent) {
                    $nextSortOrder = $repo->getNextSortOrder($company, $category->getParent());
                    $category->setSortOrder($nextSortOrder);
                }

                $em->flush();

                return $this->redirectToRoute('balance_structure_index');
            }
        }

        $links = $linkRepository->findByCompanyAndCategory($company, $category);

        return $this->render('balance_structure/edit.html.twig', [
            'form' => $form->createView(),
            'item' => $category,
            'links' => $links,
        ]);
    }

    #[Route('/{id}/delete', name: 'balance_structure_delete', methods: ['POST'])]
    public function delete(Request $request, BalanceCategory $category, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        if ($category->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete'.$category->getId(), $request->request->get('_token'))) {
            $em->remove($category);
            $em->flush();
        }

        return $this->redirectToRoute('balance_structure_index');
    }

    #[Route('/move', name: 'balance_structure_move', methods: ['POST'])]
    public function move(Request $request, BalanceCategoryRepository $repo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $categoryId = $request->request->get('category_id');
        $direction = $request->request->get('direction');

        if (!$categoryId || !$direction) {
            return $this->redirectToRoute('balance_structure_index');
        }

        $category = $repo->find($categoryId);
        if (!$category || $category->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('move'.$category->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('balance_structure_index');
        }

        $siblings = $repo->findSiblings($company, $category->getParent());
        $index = array_search($category, $siblings, true);

        if (false === $index) {
            return $this->redirectToRoute('balance_structure_index');
        }

        $swapWith = null;
        if ('up' === $direction && $index > 0) {
            $swapWith = $siblings[$index - 1];
        } elseif ('down' === $direction && $index < \count($siblings) - 1) {
            $swapWith = $siblings[$index + 1];
        }

        if ($swapWith) {
            $repo->swapSortOrder($category, $swapWith);
            $em->flush();
        }

        return $this->redirectToRoute('balance_structure_index');
    }

    #[Route('/{id}/link-money-accounts-total', name: 'balance_structure_link_money_accounts_total', methods: ['POST'])]
    public function linkMoneyAccountsTotal(Request $request, BalanceCategory $category, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        if ($category->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('link_money_accounts_total'.$category->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('balance_structure_edit', ['id' => $category->getId()]);
        }

        $this->balanceStructureSeeder->ensureLink(
            company: $company,
            category: $category,
            sourceType: BalanceLinkSourceType::MONEY_ACCOUNTS_TOTAL,
            sourceId: null,
            sign: 1,
            position: 0,
        );
        $em->flush();

        return $this->redirectToRoute('balance_structure_edit', ['id' => $category->getId()]);
    }

    #[Route('/{id}/link-money-funds-total', name: 'balance_structure_link_money_funds_total', methods: ['POST'])]
    public function linkMoneyFundsTotal(Request $request, BalanceCategory $category, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        if ($category->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('link_money_funds_total'.$category->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('balance_structure_edit', ['id' => $category->getId()]);
        }

        $this->balanceStructureSeeder->ensureLink(
            company: $company,
            category: $category,
            sourceType: BalanceLinkSourceType::MONEY_FUNDS_TOTAL,
            sourceId: null,
            sign: 1,
            position: 0,
        );
        $em->flush();

        return $this->redirectToRoute('balance_structure_edit', ['id' => $category->getId()]);
    }

    private function buildParentChoices(BalanceCategoryRepository $repo, Company $company, BalanceCategory $category): array
    {
        $excludeIds = array_merge([$category->getId()], $this->collectDescendantIds($category));

        return array_values(array_filter(
            $repo->findTreeByCompany($company),
            static function (BalanceCategory $item) use ($excludeIds): bool {
                return !\in_array($item->getId(), $excludeIds, true);
            }
        ));
    }

    /**
     * @return list<string>
     */
    private function collectDescendantIds(BalanceCategory $category): array
    {
        $ids = [];
        foreach ($category->getChildren() as $child) {
            if ($child->getId()) {
                $ids[] = $child->getId();
            }
            $ids = array_merge($ids, $this->collectDescendantIds($child));
        }

        return $ids;
    }
}

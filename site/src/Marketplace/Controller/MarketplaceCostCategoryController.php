<?php

namespace App\Marketplace\Controller;

use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use App\Marketplace\Repository\MarketplaceCostRepository;
use App\Shared\Service\CompanyContextService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace/cost-categories')]
#[IsGranted('ROLE_USER')]
class MarketplaceCostCategoryController extends AbstractController
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly MarketplaceCostCategoryRepository $repository,
        private readonly MarketplaceCostRepository $costRepository,
        private readonly EntityManagerInterface $em
    ) {}

    #[Route('', name: 'marketplace_cost_categories_index')]
    public function index(): Response
    {
        $company = $this->companyContext->getCompany();

        $categories = $this->repository->findByCompany($company);

        return $this->render('marketplace/cost_categories.html.twig', [
            'categories' => $categories,
            'availableMarketplaces' => MarketplaceType::cases(),
        ]);
    }

    #[Route('/create', name: 'marketplace_cost_categories_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $company = $this->companyContext->getCompany();

        $marketplaceValue = $request->request->get('marketplace');
        $name = trim($request->request->get('name', ''));
        $code = trim($request->request->get('code', ''));
        $description = trim($request->request->get('description', '')) ?: null;

        if (!$name || !$code || !$marketplaceValue) {
            $this->addFlash('error', 'Заполните все обязательные поля');
            return $this->redirectToRoute('marketplace_cost_categories_index');
        }

        try {
            $marketplace = MarketplaceType::from($marketplaceValue);
        } catch (\ValueError $e) {
            $this->addFlash('error', 'Неверный маркетплейс');
            return $this->redirectToRoute('marketplace_cost_categories_index');
        }

        // Проверка уникальности
        $existing = $this->repository->findByCode($company, $marketplace, $code);
        if ($existing) {
            $this->addFlash('error', sprintf('Категория с кодом "%s" уже существует для %s', $code, $marketplace->displayName));
            return $this->redirectToRoute('marketplace_cost_categories_index');
        }

        $category = new MarketplaceCostCategory(
            Uuid::uuid4()->toString(),
            $company,
            $marketplace
        );
        $category->setName($name);
        $category->setCode($code);
        $category->setDescription($description);

        $this->em->persist($category);
        $this->em->flush();

        $this->addFlash('success', sprintf('Категория "%s" создана', $name));

        return $this->redirectToRoute('marketplace_cost_categories_index');
    }

    #[Route('/{id}/edit', name: 'marketplace_cost_categories_edit', methods: ['POST'])]
    public function edit(string $id, Request $request): Response
    {
        $company = $this->companyContext->getCompany();

        $category = $this->repository->find($id);

        if (!$category || $category->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        $name = trim($request->request->get('name', ''));
        $code = trim($request->request->get('code', ''));
        $description = trim($request->request->get('description', '')) ?: null;

        if (!$name || !$code) {
            $this->addFlash('error', 'Заполните все обязательные поля');
            return $this->redirectToRoute('marketplace_cost_categories_index');
        }

        // Проверка уникальности (исключая текущую запись)
        $existing = $this->repository->findByCode($company, $category->getMarketplace(), $code);
        if ($existing && $existing->getId() !== $id) {
            $this->addFlash('error', sprintf('Категория с кодом "%s" уже существует', $code));
            return $this->redirectToRoute('marketplace_cost_categories_index');
        }

        $category->setName($name);
        $category->setCode($code);
        $category->setDescription($description);

        $this->em->flush();

        $this->addFlash('success', sprintf('Категория "%s" обновлена', $name));

        return $this->redirectToRoute('marketplace_cost_categories_index');
    }

    #[Route('/{id}/toggle', name: 'marketplace_cost_categories_toggle')]
    public function toggle(string $id): Response
    {
        $company = $this->companyContext->getCompany();

        $category = $this->repository->find($id);

        if (!$category || $category->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        $category->setIsActive(!$category->isActive());
        $this->em->flush();

        $status = $category->isActive() ? 'активирована' : 'деактивирована';
        $this->addFlash('success', sprintf('Категория "%s" %s', $category->getName(), $status));

        return $this->redirectToRoute('marketplace_cost_categories_index');
    }

    #[Route('/{id}/delete', name: 'marketplace_cost_categories_delete', methods: ['POST'])]
    public function delete(string $id): Response
    {
        $company = $this->companyContext->getCompany();

        $category = $this->repository->find($id);

        if (!$category || $category->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        // Проверка: системная категория
        if ($category->isSystem()) {
            $this->addFlash('error', 'Невозможно удалить системную категорию');
            return $this->redirectToRoute('marketplace_cost_categories_index');
        }

        // Проверка: есть ли затраты
        $costsCount = $this->costRepository->count(['category' => $category]);

        if ($costsCount > 0) {
            $this->addFlash('error', sprintf(
                'Невозможно удалить категорию "%s". Она содержит %d затрат(ы). ' .
                'Сначала удалите затраты или переназначьте их на другую категорию.',
                $category->getName(),
                $costsCount
            ));
            return $this->redirectToRoute('marketplace_cost_categories_index');
        }

        // Soft delete
        $category->softDelete();
        $this->em->flush();

        $this->addFlash('success', sprintf('Категория "%s" удалена', $category->getName()));

        return $this->redirectToRoute('marketplace_cost_categories_index');
    }
}

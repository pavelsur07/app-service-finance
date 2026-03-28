<?php

namespace App\Marketplace\Controller;

use App\Finance\Entity\PLCategory;
use App\Company\Entity\ProjectDirection;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceSaleMappingRepository;
use App\Finance\Repository\PLCategoryRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Shared\Service\CompanyContextService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace/pl-mappings')]
#[IsGranted('ROLE_USER')]
final class MarketplaceSaleMappingController extends AbstractController
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly MarketplaceSaleMappingRepository $mappingRepository,
        private readonly PLCategoryRepository $plCategoryRepository,
        private readonly ProjectDirectionRepository $projectDirectionRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'marketplace_pl_mappings_index')]
    public function index(Request $request): Response
    {
        $company = $this->companyContext->getCompany();

        $operationType = (string) $request->query->get('op', 'sale');
        if (!in_array($operationType, ['sale', 'return'], true)) {
            $operationType = 'sale';
        }

        $marketplaceFilterValue = (string) $request->query->get('marketplace', 'all');

        $marketplace = null;
        if ($marketplaceFilterValue !== 'all') {
            try {
                $marketplace = MarketplaceType::from($marketplaceFilterValue);
            } catch (\ValueError) {
                $marketplace = null;
                $marketplaceFilterValue = 'all';
            }
        }

        $mappings = $this->mappingRepository->findByCompanyFiltered($company, $marketplace, $operationType);

        $amountSources = array_values(array_filter(
            AmountSource::cases(),
            static fn (AmountSource $s): bool => $s->getOperationType() === $operationType
        ));

        $plCategories = $this->plCategoryRepository->findTreeByCompany($company);
        $projectDirections = $this->projectDirectionRepository->findByCompany($company);

        $missingAmountSources = [];
        if (null !== $marketplace) {
            $activeIndexed = $this->mappingRepository->findActiveIndexedByAmountSource($company, $marketplace, $operationType);
            foreach ($amountSources as $src) {
                if (!isset($activeIndexed[$src->value])) {
                    $missingAmountSources[] = $src;
                }
            }
        }

        return $this->render('marketplace/pl_mappings.html.twig', [
            'availableMarketplaces' => MarketplaceType::cases(),
            'marketplaceFilterValue' => $marketplaceFilterValue,
            'operationType' => $operationType,
            'amountSources' => $amountSources,
            'mappings' => $mappings,
            'plCategories' => $plCategories,
            'projectDirections' => $projectDirections,
            'missingAmountSources' => $missingAmountSources,
        ]);
    }

    #[Route('/create', name: 'marketplace_pl_mappings_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $company = $this->companyContext->getCompany();

        $marketplaceValue = (string) $request->request->get('marketplace', '');
        $amountSourceValue = (string) $request->request->get('amount_source', '');
        $plCategoryId = (string) $request->request->get('pl_category_id', '');

        if ($marketplaceValue === '' || $amountSourceValue === '' || $plCategoryId === '') {
            $this->addFlash('error', 'Заполните обязательные поля');
            return $this->redirectBackToIndex($request);
        }

        try {
            $marketplace = MarketplaceType::from($marketplaceValue);
            $amountSource = AmountSource::from($amountSourceValue);
        } catch (\ValueError) {
            $this->addFlash('error', 'Неверные значения marketplace или amountSource');
            return $this->redirectBackToIndex($request);
        }

        /** @var PLCategory|null $plCategory */
        $plCategory = $this->plCategoryRepository->find($plCategoryId);
        if (!$plCategory || $plCategory->getCompany()->getId() !== $company->getId()) {
            $this->addFlash('error', 'Категория ОПиУ не найдена');
            return $this->redirectBackToIndex($request);
        }

        $mapping = new MarketplaceSaleMapping(
            Uuid::uuid4()->toString(),
            $company,
            $marketplace,
            $amountSource,
            $plCategory,
        );

        $isNegative = (bool) $request->request->get('is_negative', false);
        $isActive = (bool) $request->request->get('is_active', false);

        $mapping->setIsNegative($isNegative);
        $mapping->setIsActive($isActive);

        $projectDirectionId = trim((string) $request->request->get('project_direction_id', ''));
        if ($projectDirectionId !== '') {
            /** @var ProjectDirection|null $pd */
            $pd = $this->projectDirectionRepository->find($projectDirectionId);
            if ($pd && $pd->getCompany()->getId() === $company->getId()) {
                $mapping->setProjectDirection($pd);
            }
        }

        if ($mapping->isActive()) {
            $deactivated = $this->mappingRepository->deactivateOtherActive(
                $company,
                $mapping->getMarketplace(),
                $mapping->getOperationType(),
                $mapping->getAmountSource(),
                null
            );

            if ($deactivated > 0) {
                $this->addFlash('success', 'Предыдущее активное правило для этого источника было деактивировано');
            }
        }

        $this->em->persist($mapping);
        $this->em->flush();

        $this->addFlash('success', 'Правило маппинга создано');

        return $this->redirectBackToIndex($request);
    }

    #[Route('/{id}/edit', name: 'marketplace_pl_mappings_edit', methods: ['POST'])]
    public function edit(string $id, Request $request): Response
    {
        $company = $this->companyContext->getCompany();

        $mapping = $this->mappingRepository->findByIdAndCompany($id, $company->getId());
        if (!$mapping) {
            throw $this->createNotFoundException();
        }

        $plCategoryId = (string) $request->request->get('pl_category_id', '');
        if ($plCategoryId === '') {
            $this->addFlash('error', 'Выберите категорию ОПиУ');
            return $this->redirectBackToIndex($request);
        }

        /** @var PLCategory|null $plCategory */
        $plCategory = $this->plCategoryRepository->find($plCategoryId);
        if (!$plCategory || $plCategory->getCompany()->getId() !== $company->getId()) {
            $this->addFlash('error', 'Категория ОПиУ не найдена');
            return $this->redirectBackToIndex($request);
        }

        $isNegative = (bool) $request->request->get('is_negative', false);
        $isActive = (bool) $request->request->get('is_active', false);

        $mapping->setPlCategory($plCategory);
        $mapping->setIsNegative($isNegative);

        $projectDirectionId = trim((string) $request->request->get('project_direction_id', ''));
        if ($projectDirectionId === '') {
            $mapping->setProjectDirection(null);
        } else {
            /** @var ProjectDirection|null $pd */
            $pd = $this->projectDirectionRepository->find($projectDirectionId);
            if ($pd && $pd->getCompany()->getId() === $company->getId()) {
                $mapping->setProjectDirection($pd);
            }
        }

        if ($isActive) {
            $this->mappingRepository->deactivateOtherActive(
                $company,
                $mapping->getMarketplace(),
                $mapping->getOperationType(),
                $mapping->getAmountSource(),
                $mapping->getId()
            );
        }
        $mapping->setIsActive($isActive);

        $this->em->flush();

        $this->addFlash('success', 'Правило маппинга обновлено');

        return $this->redirectBackToIndex($request);
    }

    #[Route('/{id}/toggle', name: 'marketplace_pl_mappings_toggle')]
    public function toggle(string $id, Request $request): Response
    {
        $company = $this->companyContext->getCompany();

        $mapping = $this->mappingRepository->findByIdAndCompany($id, $company->getId());
        if (!$mapping) {
            throw $this->createNotFoundException();
        }

        $newState = !$mapping->isActive();

        if ($newState) {
            $this->mappingRepository->deactivateOtherActive(
                $company,
                $mapping->getMarketplace(),
                $mapping->getOperationType(),
                $mapping->getAmountSource(),
                $mapping->getId()
            );
        }

        $mapping->setIsActive($newState);
        $this->em->flush();

        $this->addFlash('success', $newState ? 'Правило активировано' : 'Правило деактивировано');

        return $this->redirectBackToIndex($request);
    }

    private function redirectBackToIndex(Request $request): Response
    {
        $op = (string) $request->request->get('redirect_op', $request->query->get('op', 'sale'));
        if (!in_array($op, ['sale', 'return'], true)) {
            $op = 'sale';
        }

        $marketplace = (string) $request->request->get('redirect_marketplace', $request->query->get('marketplace', 'all'));
        if ($marketplace === '') {
            $marketplace = 'all';
        }

        return $this->redirectToRoute('marketplace_pl_mappings_index', [
            'op' => $op,
            'marketplace' => $marketplace,
        ]);
    }
}

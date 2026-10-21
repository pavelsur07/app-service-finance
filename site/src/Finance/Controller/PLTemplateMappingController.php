<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Finance\Application\Service\PLTemplateMappingBuilder;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * «Сопоставить с шаблоном»: статьи шаблона ОПиУ, которых у компании нет по коду, и выбор своих
 * статей для них — путь компании со своим деревом к шаблону без второго дерева.
 */
#[IsGranted('ROLE_USER')]
final class PLTemplateMappingController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly PLTemplateMappingBuilder $builder,
    ) {
    }

    #[Route('/pl-categories/import/template-mapping', name: 'pl_category_template_mapping', methods: ['GET'])]
    public function __invoke(): Response
    {
        $company = $this->activeCompany->getActiveCompany();

        return $this->render('pl_category/template_mapping.html.twig', [
            'mapping' => $this->builder->build($company),
            'businessProfile' => $company->getBusinessProfile(),
            'companyId' => (string) $company->getId(),
        ]);
    }
}

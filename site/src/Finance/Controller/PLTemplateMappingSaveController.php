<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Company\Security\ModuleAccess;
use App\Finance\Application\Action\BindPLCategoriesToTemplateAction;
use App\Finance\Exception\PLTemplateBindingException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class PLTemplateMappingSaveController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BindPLCategoriesToTemplateAction $bind,
    ) {
    }

    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    #[Route('/pl-categories/import/template-mapping', name: 'pl_category_template_mapping_save', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();
        if (!$this->isCsrfTokenValid('pl-template-mapping'.$companyId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $bindings = [];
        foreach ($request->request->all('map') as $code => $categoryId) {
            if (\is_string($categoryId) && '' !== $categoryId) {
                $bindings[(string) $code] = $categoryId;
            }
        }

        try {
            $bound = ($this->bind)($companyId, $bindings);
        } catch (PLTemplateBindingException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('pl_category_template_mapping');
        }

        if (0 === $bound) {
            $this->addFlash('warning', 'Не выбрано ни одной статьи.');

            return $this->redirectToRoute('pl_category_template_mapping');
        }

        $this->addFlash('success', \sprintf('Сопоставлено статей: %d. Проверьте, что добавит шаблон.', $bound));

        return $this->redirectToRoute('pl_category_import', ['source' => 'template']);
    }
}

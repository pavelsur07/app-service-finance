<?php

declare(strict_types=1);

namespace App\Company\Controller\Deletion;

use App\Company\Application\RestoreCompanyDeletionAction;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Exception\CompanyDeletionNotAllowedException;
use App\Company\Infrastructure\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Снять пометку удаления с компании до окончательной очистки. */
#[IsGranted('ROLE_USER')]
final class RestoreCompanyController extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly RestoreCompanyDeletionAction $restore,
    ) {
    }

    #[Route('/company/{id}/restore', name: 'company_restore', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(string $id, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $company = $this->companyRepository->findOneOwnedBy($id, $user);
        if (!$company instanceof Company) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('restore'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Форма устарела, обновите страницу.');

            return $this->redirectToRoute('company_index');
        }

        try {
            ($this->restore)($company, $user);
        } catch (CompanyDeletionNotAllowedException) {
            throw $this->createNotFoundException();
        }

        $this->addFlash('success', \sprintf(
            'Компания «%s» восстановлена. Подключения маркетплейсов, МойСклад и банков были отключены — включите нужные заново.',
            (string) $company->getName(),
        ));

        return $this->redirectToRoute('company_index');
    }
}

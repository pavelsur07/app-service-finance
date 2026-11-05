<?php

declare(strict_types=1);

namespace App\Company\Controller\Deletion;

use App\Company\Application\RequestCompanyDeletionAction;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Exception\CompanyDeletionConfirmationMismatchException;
use App\Company\Exception\CompanyDeletionNotAllowedException;
use App\Company\Infrastructure\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Страница подтверждения удаления компании (GET) и сам запрос удаления (POST).
 * Владелец вводит название компании; данные хранятся Company::DELETION_GRACE_DAYS дней.
 */
#[IsGranted('ROLE_USER')]
final class DeleteCompanyController extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly RequestCompanyDeletionAction $requestDeletion,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/company/{id}/delete', name: 'company_delete', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
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

        if ($company->isDeletionRequested()) {
            return $this->redirectToRoute('company_index');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('delete'.$id, (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Форма устарела, обновите страницу.');

                return $this->redirectToRoute('company_delete', ['id' => $id]);
            }

            try {
                ($this->requestDeletion)($company, $user, (string) $request->request->get('confirm_name'));
                $this->addFlash('success', \sprintf(
                    'Компания «%s» будет удалена %s. До этой даты её можно восстановить в списке компаний.',
                    (string) $company->getName(),
                    $company->getDeletionDueAt()?->format('d.m.Y') ?? '',
                ));

                return $this->redirectToRoute('company_index');
            } catch (CompanyDeletionConfirmationMismatchException $e) {
                $error = $e->getMessage();
            } catch (CompanyDeletionNotAllowedException) {
                throw $this->createNotFoundException();
            }
        }

        return $this->render('company/delete.html.twig', [
            'company' => $company,
            'dueAt' => $this->clock->now()->modify(\sprintf('+%d days', Company::DELETION_GRACE_DAYS)),
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}

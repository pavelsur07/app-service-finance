<?php

declare(strict_types=1);

namespace App\Company\Controller\Consolidation;

use App\Company\Application\ConfigureConsolidationGroupAction;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Exception\InvalidConsolidationGroupException;
use App\Company\Infrastructure\Repository\CompanyRepository;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class SaveConsolidationGroupController extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly ConfigureConsolidationGroupAction $configure,
    ) {
    }

    #[Route('/company/{id}/consolidation', name: 'company_consolidation_save', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(string $id, Request $request): Response
    {
        // Как и карточка компании — только владелец.
        $company = $this->companyRepository->find($id);
        $user = $this->getUser();
        if (!$company instanceof Company || !$user instanceof User || $company->getUser() !== $user) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('consolidation'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Форма устарела, обновите страницу.');

            return $this->redirectBack($id);
        }

        $memberIds = array_values(array_filter(
            array_map('strval', $request->request->all('members')),
            static fn (string $memberId): bool => Uuid::isValid($memberId),
        ));

        try {
            ($this->configure)($id, (string) $user->getId(), $request->request->getBoolean('enabled'), $memberIds);
        } catch (InvalidConsolidationGroupException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectBack($id);
        }

        $this->addFlash('success', 'Группа консолидации сохранена.');

        return $this->redirectBack($id);
    }

    private function redirectBack(string $companyId): Response
    {
        return $this->redirect($this->generateUrl('company_edit', ['id' => $companyId]).'#consolidation');
    }
}

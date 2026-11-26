<?php

declare(strict_types=1);

namespace App\OneC\Controller;

use App\Company\Security\ModuleAccess;
use App\OneC\Exception\ConnectionNotFoundException;
use App\OneC\Http\ConnectionCardPage;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::FINANCE_READ)]
final class ConnectionCardController extends AbstractController
{
    #[Route('/onec/connections/{id}', name: 'onec_connection_card', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $id, ActiveCompanyService $activeCompany, ConnectionCardPage $page): Response
    {
        try {
            return $page->render($id, (string) $activeCompany->getActiveCompany()->getId());
        } catch (ConnectionNotFoundException) {
            throw $this->createNotFoundException();
        }
    }
}

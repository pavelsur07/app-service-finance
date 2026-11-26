<?php

declare(strict_types=1);

namespace App\OneC\Controller;

use App\Company\Security\ModuleAccess;
use App\OneC\Infrastructure\Repository\OneCConnectionRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::FINANCE_READ)]
final class ConnectionsController extends AbstractController
{
    #[Route('/onec/connections', name: 'onec_connections', methods: ['GET'])]
    public function __invoke(ActiveCompanyService $activeCompany, OneCConnectionRepository $connections, ClockInterface $clock): Response
    {
        return $this->render('onec/index.html.twig', ['connections' => $connections->listForCompany((string) $activeCompany->getActiveCompany()->getId()), 'now' => $clock->now()]);
    }
}

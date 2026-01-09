<?php

declare(strict_types=1);

namespace App\Ai\Controller;

use App\Ai\Repository\AiSuggestionRepository;
use App\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/ai/suggestions', name: 'app_ai_suggestions_')]
final class AiSuggestionController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly AiSuggestionRepository $suggestionRepository,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $suggestions = $this->suggestionRepository->findLatestForCompany($company, 50);

        return $this->render('ai/suggestions/list.html.twig', [
            'suggestions' => $suggestions,
        ]);
    }
}

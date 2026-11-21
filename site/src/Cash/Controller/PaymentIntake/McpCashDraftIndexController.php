<?php

declare(strict_types=1);

namespace App\Cash\Controller\PaymentIntake;

use App\Cash\Application\PaymentIntake\PaymentIntakeAction;
use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class McpCashDraftIndexController extends AbstractController
{
    public function __construct(private readonly ActiveCompanyService $companies, private readonly PaymentIntakeAction $intake)
    {
    }

    #[Route('/finance/cash-transactions/mcp-drafts', name: 'cash_mcp_draft_index', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $company = $this->companies->getActiveCompany();
        $before = $request->query->has('before') ? $request->query->getString('before') : null;
        if (null !== $before && !Uuid::isValid($before)) {
            throw new BadRequestHttpException('Некорректная страница черновиков.');
        }
        $closed = $request->query->getString('status') === 'closed';
        try {
            $drafts = $this->intake->listMcpDrafts((string) $company->getId(), (string) $user->getId(), $before, $closed);
        } catch (\DomainException) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('cash/mcp_draft/index.html.twig', [
            'closed' => $closed,
            'drafts' => \array_slice($drafts, 0, 50),
            'before' => isset($drafts[50]) ? $drafts[49]['id'] : null,
        ]);
    }
}

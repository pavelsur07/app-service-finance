<?php

declare(strict_types=1);

namespace App\Settlement\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Settlement\Application\PostSettlementDocumentAction;
use App\Settlement\Query\SettlementUiQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settlements/documents/{id}/post', name: 'settlement_document_post', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class SettlementDocumentPostController extends AbstractController
{
    public function __invoke(Request $request, string $id, ActiveCompanyService $activeCompany, #[CurrentUser] User $user, SettlementUiQuery $query, PostSettlementDocumentAction $post): Response
    {
        $company = $activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();
        $document = $query->document($companyId, $id) ?? throw $this->createNotFoundException('Документ не найден.');
        if (!$this->isCsrfTokenValid('settlement_post'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Неверный защитный код формы. Обновите страницу.');
        }
        $version = filter_var($request->request->all()['version'] ?? null, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $version) {
            throw new UnprocessableEntityHttpException('Некорректная версия документа.');
        }
        try {
            $post($companyId, (string) $user->getId(), $id, $version);
            $this->addFlash('success', 'Документ проведён.');
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('settlement_document', ['id' => $document->getId()], Response::HTTP_SEE_OTHER);
    }
}

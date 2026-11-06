<?php

declare(strict_types=1);

namespace App\Settlement\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Settlement\Application\DTO\ReverseSettlementInput;
use App\Settlement\Application\ReverseSettlementAllocationAction;
use App\Settlement\Form\SettlementEventType;
use App\Settlement\Query\SettlementUiQuery;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settlements/allocations/{id}/reverse', name: 'settlement_allocation_reverse', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class SettlementAllocationReverseController extends AbstractController
{
    public function __invoke(Request $request, string $id, ActiveCompanyService $activeCompany, #[CurrentUser] User $user, SettlementUiQuery $query, ReverseSettlementAllocationAction $reverse): Response
    {
        $company = $activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();
        $book = $query->book($companyId);
        $allocation = $query->allocation($companyId, $id) ?? throw $this->createNotFoundException('Распределение не найдено.');
        $document = $query->document($companyId, $allocation->getDocumentId()) ?? throw $this->createNotFoundException();
        $form = $this->createForm(SettlementEventType::class, ['requestId' => Uuid::uuid7()->toString(), 'effectiveDate' => new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'))]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{requestId:string,effectiveDate:\DateTimeImmutable,reason:string} $data */ $data = $form->getData();
            try {
                $result = $reverse($companyId, (string) $user->getId(), $id, new ReverseSettlementInput($data['requestId'], $data['effectiveDate'], $data['reason']));

                return $this->redirectToRoute('settlement_document', ['id' => $result->documentId], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('settlement/form.html.twig', ['activeCompany' => $company, 'book' => $book, 'title' => 'Отмена распределения', 'description' => 'Отмена создаёт отдельное изменение на выбранную дату. Исходный документ и предыдущая история сохраняются. Сначала отмените зависимые изменения.', 'form' => $form, 'submitLabel' => 'Провести отмену', 'relatedDocument' => $document]);
    }
}

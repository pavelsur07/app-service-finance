<?php

declare(strict_types=1);

namespace App\Settlement\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Settlement\Application\ClassifySettlementPaymentAction;
use App\Settlement\Application\DTO\ReverseSettlementInput;
use App\Settlement\Form\SettlementClassificationType;
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

#[Route('/settlements/payments/{id}/classify', name: 'settlement_payment_classify', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class SettlementPaymentClassifyController extends AbstractController
{
    public function __invoke(Request $request, string $id, ActiveCompanyService $activeCompany, #[CurrentUser] User $user, SettlementUiQuery $query, ClassifySettlementPaymentAction $classify): Response
    {
        $company = $activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();
        $book = $query->book($companyId);
        $item = $query->item($companyId, $id) ?? throw $this->createNotFoundException('Оплата не найдена.');
        if (!in_array($item->getBucket(), [\App\Settlement\Enum\SettlementBucket::ADVANCE_GIVEN, \App\Settlement\Enum\SettlementBucket::ADVANCE_RECEIVED, \App\Settlement\Enum\SettlementBucket::UNALLOCATED_OUT, \App\Settlement\Enum\SettlementBucket::UNALLOCATED_IN], true)) {
            throw $this->createNotFoundException('Оплата не найдена.');
        }
        $form = $this->createForm(SettlementClassificationType::class, ['requestId' => Uuid::uuid7()->toString(), 'effectiveDate' => new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')), 'isAdvance' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{requestId:string,effectiveDate:\DateTimeImmutable,reason:string,isAdvance:bool} $data */ $data = $form->getData();
            try {
                $classify($companyId, (string) $user->getId(), $id, $data['isAdvance'], new ReverseSettlementInput($data['requestId'], $data['effectiveDate'], $data['reason']));

                return $this->redirectToRoute('settlement_card', ['id' => $item->getCounterpartyId()], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('settlement/form.html.twig', ['activeCompany' => $company, 'book' => $book, 'title' => 'Классификация оплаты', 'description' => 'Переносится только свободный остаток оплаты. Уже погашенная задолженность и сумма ДДС не меняются.', 'form' => $form, 'submitLabel' => 'Изменить классификацию']);
    }
}

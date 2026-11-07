<?php

declare(strict_types=1);

namespace App\Settlement\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Settlement\Application\AllocateSettlementAction;
use App\Settlement\Application\DTO\AllocateSettlementInput;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Form\SettlementAllocationType;
use App\Settlement\Form\SettlementCounterpartyType;
use App\Settlement\Query\SettlementUiQuery;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settlements/allocations/new', name: 'settlement_allocation_new', methods: ['GET', 'POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_READ)]
final class SettlementAllocationNewController extends AbstractController
{
    public function __invoke(Request $request, ActiveCompanyService $activeCompany, #[CurrentUser] User $user, SettlementUiQuery $query, AllocateSettlementAction $apply): Response
    {
        $company = $activeCompany->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $companyId = (string) $company->getId();
        $book = $query->book($companyId);
        if (null === $book) {
            return $this->redirectToRoute('settlement_setup');
        }
        $partyParameter = $request->query->all()['counterparty'] ?? null;
        $keepId = is_string($partyParameter) && Uuid::isValid($partyParameter) ? $partyParameter : null;
        $partyForm = $this->createForm(SettlementCounterpartyType::class, null, ['company_id' => $companyId, 'keep_id' => $keepId]);
        if ($request->query->has('counterparty')) {
            $partyForm->submit(['counterparty' => $partyParameter]);
        }
        $form = null;
        $positions = [];
        $party = null;
        if ($partyForm->isSubmitted() && $partyForm->isValid()) {
            /** @var array{counterparty:string} $partyData */ $partyData = $partyForm->getData();
            $party = $partyData['counterparty'];
            $positions = $query->positions($companyId, $party);
            $obligations = [];
            $payments = [];
            $submitted = $request->request->all()['settlement_allocation'] ?? [];
            $submittedIds = is_array($submitted) ? [$submitted['obligationItemId'] ?? null, $submitted['paymentItemId'] ?? null] : [];
            foreach ($positions as $position) {
                // Preserve scoped posted choices so a lost-response retry reaches Action replay.
                if (!$position->available->isPositive() && !in_array($position->itemId, $submittedIds, true)) {
                    continue;
                }
                $label = $position->label().' · '.(in_array($position->bucket, [SettlementBucket::PAYABLE, SettlementBucket::ADVANCE_GIVEN, SettlementBucket::UNALLOCATED_OUT], true) ? 'Поставщик' : 'Покупатель');
                if (in_array($position->bucket, [SettlementBucket::PAYABLE, SettlementBucket::RECEIVABLE], true)) {
                    $obligations[$label] = $position->itemId;
                } else {
                    $payments[$label] = $position->itemId;
                }
            }
            $form = $this->createForm(SettlementAllocationType::class, ['requestId' => Uuid::uuid7()->toString(), 'effectiveDate' => new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'))], ['obligations' => $obligations, 'payments' => $payments]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var array{requestId:string,obligationItemId:string,paymentItemId:string,amount:\App\Shared\Domain\ValueObject\Money,effectiveDate:\DateTimeImmutable} $data */ $data = $form->getData();
                try {
                    $apply($companyId, (string) $user->getId(), new AllocateSettlementInput($data['requestId'], $data['obligationItemId'], $data['paymentItemId'], $data['amount'], $data['effectiveDate']));
                    $this->addFlash('success', 'Оплата распределена. Сумма ДДС не изменена.');

                    return $this->redirectToRoute('settlement_card', ['id' => $party], Response::HTTP_SEE_OTHER);
                } catch (\DomainException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                }
            }
        }

        return $this->render('settlement/positions_form.html.twig', ['activeCompany' => $company, 'book' => $book, 'title' => 'Распределение оплаты', 'form' => $form, 'partyForm' => $partyForm, 'positions' => $positions, 'counterpartyId' => $party, 'submitLabel' => 'Распределить']);
    }
}

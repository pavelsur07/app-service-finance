<?php

declare(strict_types=1);

namespace App\Settlement\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Settlement\Application\CorrectSettlementItemAction;
use App\Settlement\Application\DTO\CorrectSettlementItemInput;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Form\SettlementCorrectionType;
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

#[Route('/settlements/corrections/new', name: 'settlement_correction_new', methods: ['GET', 'POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_READ)]
final class SettlementCorrectionNewController extends AbstractController
{
    public function __invoke(Request $request, ActiveCompanyService $activeCompany, #[CurrentUser] User $user, SettlementUiQuery $query, CorrectSettlementItemAction $apply): Response
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
            $choices = [];
            foreach ($positions as $position) {
                if (SettlementDocumentKind::PAYMENT !== $position->kind) {
                    $choices[$position->label()] = $position->itemId;
                }
            }
            $form = $this->createForm(SettlementCorrectionType::class, ['requestId' => Uuid::uuid7()->toString(), 'effectiveDate' => new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')), 'increase' => true], ['items' => $choices]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var array{requestId:string,itemId:string,amount:\App\Shared\Domain\ValueObject\Money,increase:bool,effectiveDate:\DateTimeImmutable,reason:string} $data */ $data = $form->getData();
                try {
                    $apply($companyId, (string) $user->getId(), new CorrectSettlementItemInput($data['requestId'], $data['itemId'], $data['amount'], $data['increase'], $data['effectiveDate'], $data['reason']));
                    $this->addFlash('success', 'Корректировка проведена.');

                    return $this->redirectToRoute('settlement_card', ['id' => $party], Response::HTTP_SEE_OTHER);
                } catch (\DomainException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                }
            }
        }

        return $this->render('settlement/positions_form.html.twig', ['activeCompany' => $company, 'book' => $book, 'title' => 'Корректировка', 'form' => $form, 'partyForm' => $partyForm, 'positions' => $positions, 'counterpartyId' => $party, 'submitLabel' => 'Провести корректировку']);
    }
}

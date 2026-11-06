<?php

declare(strict_types=1);

namespace App\Settlement\Controller;

use App\Cash\Application\DTO\CashSettlementSource;
use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Settlement\Application\DTO\RegisterCashSettlementInput;
use App\Settlement\Application\PreviewSettlementSourceAction;
use App\Settlement\Application\RegisterCashSettlementAction;
use App\Settlement\Enum\SettlementSourceType;
use App\Settlement\Form\SettlementSourceType as SettlementSourceFormType;
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

#[Route('/settlements/sources/cash/{id}', name: 'settlement_source_cash', methods: ['GET', 'POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class SettlementCashSourceController extends AbstractController
{
    public function __invoke(Request $request, string $id, ActiveCompanyService $activeCompany, #[CurrentUser] User $user, SettlementUiQuery $query, PreviewSettlementSourceAction $preview, RegisterCashSettlementAction $include): Response
    {
        $company = $activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }
        $book = $query->book($companyId);
        if (null === $book) {
            return $this->redirectToRoute('settlement_setup');
        }
        try {
            $source = $preview($companyId, (string) $user->getId(), SettlementSourceType::CASH, $id);
        } catch (\DomainException $exception) {
            throw $this->createNotFoundException('Источник не найден.', $exception);
        }
        if (!$source instanceof CashSettlementSource) {
            throw new \LogicException('Unexpected source type.');
        }
        $link = $query->sourceLink($companyId, SettlementSourceType::CASH, $id);
        $effectiveDate = $source->occurredAt;
        if (null !== $link?->getLastReversalDate() && $link->getLastReversalDate() > $effectiveDate) {
            $effectiveDate = $link->getLastReversalDate();
        }
        $form = $this->createForm(SettlementSourceFormType::class, ['requestId' => Uuid::uuid7()->toString(), 'effectiveDate' => $effectiveDate, 'isAdvance' => false], ['source_kind' => 'cash']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{requestId:string,effectiveDate:\DateTimeImmutable,isAdvance:bool} $data */ $data = $form->getData();
            try {
                $result = $include($companyId, (string) $user->getId(), new RegisterCashSettlementInput($data['requestId'], $id, $data['isAdvance'], $data['effectiveDate']));

                return $this->redirectToRoute('settlement_document', ['id' => $result->documentId], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('settlement/source.html.twig', ['activeCompany' => $company, 'book' => $book, 'form' => $form, 'source' => $source, 'sourceKind' => 'cash', 'link' => $link]);
    }
}

<?php

declare(strict_types=1);

namespace App\Settlement\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Finance\Application\DTO\FinanceSettlementSource;
use App\Settlement\Application\CreateSettlementFromFinanceAction;
use App\Settlement\Application\DTO\CreateSettlementFromFinanceInput;
use App\Settlement\Application\PreviewSettlementSourceAction;
use App\Settlement\Enum\SettlementBucket;
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

#[Route('/settlements/sources/finance/{id}', name: 'settlement_source_finance', methods: ['GET', 'POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_READ)]
final class SettlementFinanceSourceController extends AbstractController
{
    public function __invoke(Request $request, string $id, ActiveCompanyService $activeCompany, #[CurrentUser] User $user, SettlementUiQuery $query, PreviewSettlementSourceAction $preview, CreateSettlementFromFinanceAction $include): Response
    {
        $company = $activeCompany->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $companyId = (string) $company->getId();
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }
        $book = $query->book($companyId);
        if (null === $book) {
            return $this->redirectToRoute('settlement_setup');
        }
        try {
            $source = $preview($companyId, (string) $user->getId(), SettlementSourceType::FINANCE, $id);
        } catch (\DomainException $exception) {
            throw $this->createNotFoundException('Источник не найден.', $exception);
        }
        if (!$source instanceof FinanceSettlementSource) {
            throw new \LogicException('Unexpected source type.');
        }
        $link = $query->sourceLink($companyId, SettlementSourceType::FINANCE, $id);
        $effectiveDate = $source->date;
        if (null !== $link?->getLastReversalDate() && $link->getLastReversalDate() > $effectiveDate) {
            $effectiveDate = $link->getLastReversalDate();
        }
        $form = $this->createForm(SettlementSourceFormType::class, ['requestId' => Uuid::uuid7()->toString(), 'effectiveDate' => $effectiveDate, 'bucket' => 'payable', 'reason' => 'Основание по документу ОПиУ'], ['source_kind' => 'finance']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{requestId:string,effectiveDate:\DateTimeImmutable,bucket:string,amount:\App\Shared\Domain\ValueObject\Money,dueDate:?\DateTimeImmutable,reason:string} $data */ $data = $form->getData();
            try {
                $result = $include($companyId, (string) $user->getId(), new CreateSettlementFromFinanceInput($data['requestId'], $id, SettlementBucket::from($data['bucket']), $data['amount'], $data['effectiveDate'], $data['dueDate'], $data['reason']));

                return $this->redirectToRoute('settlement_document', ['id' => $result->documentId], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('settlement/source.html.twig', ['activeCompany' => $company, 'book' => $book, 'form' => $form, 'source' => $source, 'sourceKind' => 'finance', 'link' => $link]);
    }
}

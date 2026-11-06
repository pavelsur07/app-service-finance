<?php

declare(strict_types=1);

namespace App\Settlement\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Settlement\Application\CreateSettlementDocumentAction;
use App\Settlement\Application\DTO\CreateSettlementDocumentInput;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Settlement\Form\SettlementDocumentType;
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

#[Route('/settlements/documents/new', name: 'settlement_document_new', methods: ['GET', 'POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class SettlementDocumentNewController extends AbstractController
{
    public function __invoke(Request $request, ActiveCompanyService $activeCompany, #[CurrentUser] User $user, SettlementUiQuery $query, CreateSettlementDocumentAction $create): Response
    {
        $company = $activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();
        $book = $query->book($companyId);
        if (null === $book) {
            return $this->redirectToRoute('settlement_setup');
        }
        $form = $this->createForm(SettlementDocumentType::class, ['requestId' => Uuid::uuid7()->toString(), 'kind' => 'obligation', 'bucket' => 'payable', 'effectiveDate' => new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'))], ['company_id' => $companyId]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{requestId:string,kind:string,counterpartyId:string,bucket:string,amount:\App\Shared\Domain\ValueObject\Money,effectiveDate:\DateTimeImmutable,dueDate:?\DateTimeImmutable,reason:string} $data */ $data = $form->getData();
            try {
                $result = $create($companyId, (string) $user->getId(), new CreateSettlementDocumentInput($data['requestId'], SettlementDocumentKind::from($data['kind']), $data['counterpartyId'], SettlementBucket::from($data['bucket']), $data['amount'], $data['effectiveDate'], $data['dueDate'], $data['reason']));

                return $this->redirectToRoute('settlement_document', ['id' => $result->documentId], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('settlement/form.html.twig', ['activeCompany' => $company, 'title' => 'Новый документ', 'description' => 'Основание долга создаётся независимо от оплаты. Начальные остатки вводятся на дату начала учёта; авансы доступны только для начальных остатков.', 'form' => $form, 'submitLabel' => 'Сохранить черновик', 'book' => $book]);
    }
}

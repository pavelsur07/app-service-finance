<?php

declare(strict_types=1);

namespace App\Settlement\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Settlement\Application\ConfigureSettlementBookAction;
use App\Settlement\Form\SettlementBookType;
use App\Settlement\Query\SettlementUiQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settlements/setup', name: 'settlement_setup', methods: ['GET', 'POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_READ)]
final class SettlementBookSetupController extends AbstractController
{
    public function __invoke(Request $request, ActiveCompanyService $activeCompany, #[CurrentUser] User $user, SettlementUiQuery $query, ConfigureSettlementBookAction $configure): Response
    {
        $company = $activeCompany->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $companyId = (string) $company->getId();
        $book = $query->book($companyId);
        if (null !== $book && $request->isMethod('POST')) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Дата начала уже настроена.');
        }
        $form = $this->createForm(SettlementBookType::class, ['startDate' => $book?->getStartDate() ?? new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'))], ['disabled' => null !== $book, 'csrf_token_id' => 'settlement_setup_'.$companyId]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{startDate:\DateTimeImmutable} $data */ $data = $form->getData();
            try {
                $configure($companyId, (string) $user->getId(), $data['startDate']);
                $this->addFlash('success', 'Дата начала учёта сохранена. Введите начальные остатки или создайте основания долга.');

                return $this->redirectToRoute('settlement_index', status: Response::HTTP_SEE_OTHER);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('settlement/setup.html.twig', ['activeCompany' => $company, 'book' => $book, 'form' => $form]);
    }
}

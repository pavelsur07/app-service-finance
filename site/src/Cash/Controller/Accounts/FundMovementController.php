<?php

declare(strict_types=1);

namespace App\Cash\Controller\Accounts;

use App\Cash\Application\Funds\CreateMoneyFundMovementAction;
use App\Cash\Form\Accounts\MoneyFundMovementType;
use App\Cash\Repository\Accounts\MoneyFundRepository;
use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Shared\Domain\Exception\MoneyOverflowException;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\FeatureFlagService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/funds')]
final class FundMovementController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MoneyFundRepository $fundRepository,
        private readonly CreateMoneyFundMovementAction $createMovement,
        private readonly FeatureFlagService $featureFlagService,
    ) {
    }

    #[Route('/{id}/movement', name: 'finance_funds_add_movement', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        $this->assertFeatureEnabled();
        $company = $this->activeCompanyService->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $fund = $this->fundRepository->findOneByIdAndCompanyId($id, (string) $company->getId()) ?? throw $this->createNotFoundException();
        $movement = $this->createMovement->createDraft($fund, $this->creationKey($request));
        $form = $this->createForm(MoneyFundMovementType::class, $movement, ['currency' => $fund->getCurrency()]);
        if (!$request->isMethod(Request::METHOD_POST)) {
            $form->get('amount')->setData('0');
        }
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->createMovement->setAmount($movement, (string) $form->get('amount')->getData());
            } catch (\InvalidArgumentException|MoneyOverflowException) {
                $form->get('amount')->addError(new FormError('Некорректная сумма или превышен допустимый размер.'));
            }
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            ($this->createMovement)($movement, $user instanceof User ? $user->getId() : null);
            $this->addFlash('success', 'Движение по фонду создано.');

            return $this->redirectToRoute('finance_funds_index');
        }

        return $this->render('fund/movement.html.twig', ['fund' => $fund, 'form' => $form->createView()], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    private function assertFeatureEnabled(): void
    {
        if (!$this->featureFlagService->isFundsAndWidgetEnabled()) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * ID создаваемого движения: ключ повтора из отправленной формы, иначе новый UUIDv7 — он
     * уходит в форму скрытым полем, и повтор той же отправки несёт тот же ключ.
     */
    private function creationKey(Request $request): string
    {
        $key = $request->request->all('money_fund_movement')['idempotencyKey'] ?? null;

        return \is_string($key) && Uuid::isValid($key) ? Uuid::fromString($key)->toString() : Uuid::uuid7()->toString();
    }
}

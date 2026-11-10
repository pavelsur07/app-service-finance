<?php

declare(strict_types=1);

namespace App\Cash\Controller\Accounts;

use App\Cash\Application\CreateMoneyFundMovementAction;
use App\Cash\Application\SaveMoneyFundAction;
use App\Cash\Entity\Accounts\MoneyFund;
use App\Cash\Entity\Accounts\MoneyFundMovement;
use App\Cash\Form\Accounts\MoneyFundMovementType;
use App\Cash\Form\Accounts\MoneyFundType;
use App\Cash\Repository\Accounts\MoneyFundRepository;
use App\Cash\Service\Accounts\FundBalanceService;
use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Shared\Domain\Exception\MoneyOverflowException;
use App\Shared\Domain\ValueObject\Money;
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
class FundController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly FeatureFlagService $featureFlagService,
    ) {
    }

    #[Route('', name: 'finance_funds_index', methods: ['GET'])]
    public function index(MoneyFundRepository $fundRepository, FundBalanceService $fundBalanceService): Response
    {
        /* $this->assertFeatureEnabled(); */
        $company = $this->activeCompanyService->getActiveCompany();

        $funds = $fundRepository->findByCompany($company);
        $balances = [];
        $balancesDecimal = [];
        foreach ($fundBalanceService->getFundBalances($company->getId()) as $row) {
            $balances[$row['fundId']] = $row['balanceMinor'];
            $balancesDecimal[$row['fundId']] = $fundBalanceService->convertMinorToDecimal($row['balanceMinor'], $row['currency']);
        }

        return $this->render('fund/index.html.twig', [
            'funds' => $funds,
            'balances' => $balances,
            'balancesDecimal' => $balancesDecimal,
        ]);
    }

    #[Route('/new', name: 'finance_funds_new', methods: ['GET', 'POST'])]
    public function new(Request $request, SaveMoneyFundAction $saveFund): Response
    {
        $this->assertFeatureEnabled();
        $company = $this->activeCompanyService->getActiveCompany();

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $fund = new MoneyFund(Uuid::uuid4()->toString(), $company, '', 'RUB');
        $form = $this->createForm(MoneyFundType::class, $fund);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $fund->setCompany($company);
            $saveFund($fund);

            $this->addFlash('success', 'Фонд создан.');

            return $this->redirectToRoute('finance_funds_index');
        }

        return $this->render('fund/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/movement', name: 'finance_funds_add_movement', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function addMovement(
        string $id,
        Request $request,
        MoneyFundRepository $fundRepository,
        CreateMoneyFundMovementAction $createMovement,
    ): Response {
        $this->assertFeatureEnabled();
        $company = $this->activeCompanyService->getActiveCompany();

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $fund = $fundRepository->findOneByIdAndCompanyId($id, (string) $company->getId())
            ?? throw $this->createNotFoundException();

        $movement = new MoneyFundMovement(
            $this->creationKey($request),
            $company,
            $fund,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $form = $this->createForm(MoneyFundMovementType::class, $movement, [
            'currency' => $fund->getCurrency(),
        ]);
        if (!$request->isMethod(Request::METHOD_POST)) {
            $form->get('amount')->setData('0');
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $amountInput = str_replace(["\u{202F}", "\u{2009}"], ' ', (string) $form->get('amount')->getData());
            try {
                $movement->setAmountMinor(Money::fromString('' === trim($amountInput) ? '0' : $amountInput, $fund->getCurrency())->amountMinor());
            } catch (\InvalidArgumentException|MoneyOverflowException) {
                $form->get('amount')->addError(new FormError('Некорректная сумма или превышен допустимый размер.'));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            $movement->setUserId($user instanceof User ? $user->getId() : null);

            $createMovement($movement);

            $this->addFlash('success', 'Движение по фонду создано.');

            return $this->redirectToRoute('finance_funds_index');
        }

        return $this->render('fund/movement.html.twig', [
            'fund' => $fund,
            'form' => $form->createView(),
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
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

    private function assertFeatureEnabled(): void
    {
        if (!$this->featureFlagService->isFundsAndWidgetEnabled()) {
            throw $this->createNotFoundException();
        }
    }
}

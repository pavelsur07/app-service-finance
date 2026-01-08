<?php

namespace App\Cash\Controller\Accounts;

use App\Cash\Form\Accounts\MoneyFundType;
use App\Cash\Service\Accounts\FundBalanceService;
use App\Entity\MoneyFund;
use App\Entity\MoneyFundMovement;
use App\Entity\User;
use App\Form\MoneyFundMovementType;
use App\Repository\MoneyFundRepository;
use App\Service\ActiveCompanyService;
use App\Service\FeatureFlagService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Routing\Attribute\Route;

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
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->assertFeatureEnabled();
        $company = $this->activeCompanyService->getActiveCompany();

        $fund = new MoneyFund(Uuid::uuid4()->toString(), $company, '', 'RUB');
        $form = $this->createForm(MoneyFundType::class, $fund);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $fund->setCompany($company);
            $entityManager->persist($fund);
            $entityManager->flush();

            $this->addFlash('success', 'Фонд создан.');

            return $this->redirectToRoute('finance_funds_index');
        }

        return $this->render('fund/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/movement', name: 'finance_funds_add_movement', methods: ['GET', 'POST'])]
    public function addMovement(
        MoneyFund $fund,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->assertFeatureEnabled();
        $company = $this->activeCompanyService->getActiveCompany();

        if ($fund->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        $movement = new MoneyFundMovement(
            Uuid::uuid4()->toString(),
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
            $amountInput = (string) $form->get('amount')->getData();
            $movement->setAmountMinor($this->convertToMinorUnits($amountInput, $fund->getCurrency()));

            $user = $this->getUser();
            $movement->setUserId($user instanceof User ? $user->getId() : null);

            $entityManager->persist($movement);
            $entityManager->flush();

            $this->addFlash('success', 'Движение по фонду создано.');

            return $this->redirectToRoute('finance_funds_index');
        }

        return $this->render('fund/movement.html.twig', [
            'fund' => $fund,
            'form' => $form->createView(),
        ]);
    }

    private function assertFeatureEnabled(): void
    {
        if (!$this->featureFlagService->isFundsAndWidgetEnabled()) {
            throw $this->createNotFoundException();
        }
    }

    private function convertToMinorUnits(string $amount, string $currency): int
    {
        $normalized = str_replace(["\xC2\xA0", ' '], '', str_replace(',', '.', trim($amount)));
        if ('' === $normalized) {
            return 0;
        }

        if (!is_numeric($normalized)) {
            throw new \InvalidArgumentException('Некорректная сумма.');
        }

        $fractionDigits = Currencies::getFractionDigits($currency);

        if (0 === $fractionDigits) {
            return (int) round((float) $normalized);
        }

        $scale = (string) 10 ** $fractionDigits;
        $scaled = bcmul($normalized, $scale, 0);

        return (int) $scaled;
    }
}

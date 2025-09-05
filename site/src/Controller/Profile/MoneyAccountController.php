<?php

namespace App\Controller\Profile;

use App\Entity\MoneyAccount;
use App\Enum\MoneyAccountType;
use App\Form\MoneyAccountType as MoneyAccountFormType;
use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use App\Service\AccountBalanceService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/accounts')]
class MoneyAccountController extends AbstractController
{
    public function __construct(private ActiveCompanyService $activeCompanyService)
    {
    }

    #[Route('/', name: 'money_account_index', methods: ['GET'])]
    public function index(MoneyAccountRepository $repository): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $accounts = $repository->findBy(['company' => $company]);

        return $this->render('profile/money_account/index.html.twig', [
            'accounts' => $accounts,
        ]);
    }

    #[Route('/new', name: 'money_account_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $account = new MoneyAccount(
            id: Uuid::uuid4()->toString(),
            company: $company,
            type: MoneyAccountType::BANK,
            name: '',
            currency: 'RUB'
        );

        $form = $this->createForm(MoneyAccountFormType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($account);
            $em->flush();
            return $this->redirectToRoute('money_account_index');
        }

        return $this->render('profile/money_account/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'money_account_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        MoneyAccount $account,
        EntityManagerInterface $em,
        AccountBalanceService $balanceService
    ): Response {
        $form = $this->createForm(MoneyAccountFormType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $company = $account->getCompany();
            $balanceService->recalculateDailyRange(
                $company,
                $account,
                $account->getOpeningBalanceDate(),
                new \DateTimeImmutable('today')
            );
            $todayBalance = $balanceService->getBalanceOnDate(
                $company,
                $account,
                new \DateTimeImmutable('today')
            );
            $account->setCurrentBalance($todayBalance->closing);
            $em->flush();

            return $this->redirectToRoute('money_account_index');
        }

        return $this->render('profile/money_account/edit.html.twig', [
            'form' => $form->createView(),
            'account' => $account,
        ]);
    }
}

<?php

namespace App\Controller\Finance;

use App\DTO\CashTransactionDTO;
use App\Entity\CashTransaction;
use App\Form\CashTransactionType;
use App\Repository\CashflowCategoryRepository;
use App\Repository\CashTransactionRepository;
use App\Repository\CounterpartyRepository;
use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use App\Service\CashTransactionService;
use Doctrine\ORM\Exception\ORMException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Form\FormError;

#[Route('/finance/cash-transactions')]
class CashTransactionController extends AbstractController
{
    public function __construct(private ActiveCompanyService $companyService)
    {
    }

    #[Route('/', name: 'cash_transaction_index', methods: ['GET'])]
    public function index(
        Request $request,
        CashTransactionRepository $txRepo,
        MoneyAccountRepository $accountRepo,
        CashflowCategoryRepository $categoryRepo,
        CounterpartyRepository $counterpartyRepo,
    ): Response {
        $company = $this->companyService->getActiveCompany();

        $filters = [
            'dateFrom' => $request->query->get('dateFrom'),
            'dateTo' => $request->query->get('dateTo'),
            'accountId' => $request->query->get('accountId'),
            'categoryId' => $request->query->get('categoryId'),
            'counterpartyId' => $request->query->get('counterpartyId'),
            'direction' => $request->query->get('direction'),
            'amountMin' => $request->query->get('amountMin'),
            'amountMax' => $request->query->get('amountMax'),
            'q' => $request->query->get('q'),
        ];

        $qb = $txRepo->createQueryBuilder('t')
            ->andWhere('t.company = :company')
            ->setParameter('company', $company)
            ->orderBy('t.occurredAt', 'DESC');

        if ($filters['dateFrom']) {
            $qb->andWhere('t.occurredAt >= :df')->setParameter('df', new \DateTimeImmutable($filters['dateFrom']));
        }
        if ($filters['dateTo']) {
            $qb->andWhere('t.occurredAt <= :dt')->setParameter('dt', new \DateTimeImmutable($filters['dateTo']));
        }
        if ($filters['accountId']) {
            $qb->andWhere('t.moneyAccount = :acc')->setParameter('acc', $filters['accountId']);
        }
        if ($filters['categoryId']) {
            $qb->andWhere('t.cashflowCategory = :cat')->setParameter('cat', $filters['categoryId']);
        }
        if ($filters['counterpartyId']) {
            $qb->andWhere('t.counterparty = :cp')->setParameter('cp', $filters['counterpartyId']);
        }
        if ($filters['direction']) {
            $qb->andWhere('t.direction = :dir')->setParameter('dir', $filters['direction']);
        }
        if ($filters['amountMin']) {
            $qb->andWhere('t.amount >= :amin')->setParameter('amin', $filters['amountMin']);
        }
        if ($filters['amountMax']) {
            $qb->andWhere('t.amount <= :amax')->setParameter('amax', $filters['amountMax']);
        }
        if ($filters['q']) {
            $qb->andWhere('t.description LIKE :q')->setParameter('q', '%'.$filters['q'].'%');
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;

        $qbCount = clone $qb;
        $qbCount->resetDQLPart('orderBy');
        $total = (int) $qbCount->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();

        $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        $transactions = $qb->getQuery()->getResult();

        $pages = (int) ceil($total / $limit);
        $pager = [
            'current' => $page,
            'pages' => $pages,
            'hasPrevious' => $page > 1,
            'hasNext' => $page < $pages,
            'previous' => $page - 1,
            'next' => $page + 1,
        ];

        $sumQb = clone $qbCount;
        $sum = $sumQb->select(
            "SUM(CASE WHEN t.direction = 'INFLOW' THEN t.amount ELSE 0 END) as inflow",
            "SUM(CASE WHEN t.direction = 'OUTFLOW' THEN t.amount ELSE 0 END) as outflow"
        )->getQuery()->getSingleResult();
        $summary = [
            'inflow' => $sum['inflow'] ?? 0,
            'outflow' => $sum['outflow'] ?? 0,
            'net' => ($sum['inflow'] ?? 0) - ($sum['outflow'] ?? 0),
        ];

        $accounts = $accountRepo->findBy(['company' => $company]);
        $categories = $categoryRepo->findTreeByCompany($company);
        $counterparties = $counterpartyRepo->findBy(['company' => $company], ['name' => 'ASC']);

        return $this->render('transaction/index.html.twig', [
            'transactions' => $transactions,
            'filters' => $filters,
            'accounts' => $accounts,
            'categories' => $categories,
            'counterparties' => $counterparties,
            'summary' => $summary,
            'pager' => $pager,
        ]);
    }

    /**
     * @throws ORMException
     */
    #[Route('/new', name: 'cash_transaction_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        CashTransactionService $service,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        $dto = new CashTransactionDTO();
        $dto->occurredAt = new \DateTimeImmutable('today');

        $form = $this->createForm(CashTransactionType::class, $dto, ['company' => $company]);

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                /** @var CashTransactionDTO $data */
                $data = $form->getData();
                try {
                    $service->add($data);
                    $this->addFlash('success', 'Транзакция добавлена');

                    return $this->redirectToRoute('cash_transaction_index');
                } catch (\DomainException $e) {
                    // Период закрыт — показать ошибку пользователю
                    $form->addError(new FormError($e->getMessage()));
                    $this->addFlash('danger', $e->getMessage());
                    // остаёмся на форме (без редиректа)
                }
            } else {
                return $this->json($form->getErrors(true));
            }
        }

        return $this->render('transaction/new.html.twig', [
            'form' => $form->createView(),
            'tx' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'cash_transaction_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        CashTransaction $tx,
        CashTransactionService $service,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        if ($tx->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $dto = new CashTransactionDTO();
        $dto->occurredAt = $tx->getOccurredAt();
        $dto->amount = $tx->getAmount();
        $dto->direction = $tx->getDirection();
        $dto->description = $tx->getDescription();

        $form = $this->createForm(CashTransactionType::class, $dto, ['company' => $company]);

        $form->get('moneyAccount')->setData($tx->getMoneyAccount());
        $form->get('cashflowCategory')->setData($tx->getCashflowCategory());
        $form->get('counterparty')->setData($tx->getCounterparty());
        $form->get('projectDirection')->setData($tx->getProjectDirection());

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                /** @var CashTransactionDTO $data */
                $data = $form->getData();
                try {
                    $service->update($tx, $data);
                    $this->addFlash('success', 'Транзакция обновлена');

                    return $this->redirectToRoute('cash_transaction_index');
                } catch (\DomainException $e) {
                    // Период закрыт — показать ошибку и остаться на форме
                    $form->addError(new FormError($e->getMessage()));
                    $this->addFlash('danger', $e->getMessage());
                }
            }
        }

        return $this->render('transaction/edit.html.twig', [
            'form' => $form->createView(),
            'tx' => $tx,
        ]);
    }

    #[Route('/{id}/delete', name: 'cash_transaction_delete', methods: ['POST'])]
    public function delete(Request $request, CashTransaction $tx, CashTransactionService $service): Response
    {
        $company = $this->companyService->getActiveCompany();
        if ($tx->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('tx_delete'.$tx->getId(), $request->request->get('_token'))) {
            try {
                $service->delete($tx);
                $this->addFlash('success', 'Транзакция удалена');
            } catch (\DomainException $e) {
                // Период закрыт — показать сообщение и вернуть на список
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->addFlash('danger', 'Неверный CSRF токен');
        }

        return $this->redirectToRoute('cash_transaction_index');
    }
}

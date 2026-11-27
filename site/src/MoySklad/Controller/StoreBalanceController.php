<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Balance\Facade\BalanceFacade;
use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\Company\Security\ModuleAccess;
use App\MoySklad\Application\StoreAccountSuggestions;
use App\MoySklad\Enum\StoreBalanceStatus;
use App\MoySklad\Infrastructure\Query\MoySkladConnectionsQuery;
use App\MoySklad\Infrastructure\Query\StoreBalanceQuery;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\Shared\Service\ActiveCompanyService;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class StoreBalanceController extends AbstractController
{
    public static function csrfId(string $companyId, string $connectionId): string
    {
        return 'moysklad_store_balance'.$companyId.$connectionId;
    }

    #[Route('/moy-sklad/stores', name: 'moysklad_stores_entry', methods: ['GET'])]
    public function entry(Request $request, ActiveCompanyService $activeCompany, MoySkladConnectionsQuery $query): Response
    {
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        try {
            $pager = Pagerfanta::createForCurrentPageWithMaxPerPage(new QueryAdapter($query->forCompany($companyId)), max(1, $request->query->getInt('page', 1)), 20);
        } catch (OutOfRangeCurrentPageException) {
            throw new UnprocessableEntityHttpException('Страница не существует.');
        }
        if (1 === $pager->getNbResults()) {
            return $this->redirectToRoute('moysklad_stores', ['connectionId' => iterator_to_array($pager->getCurrentPageResults())[0]->getId()]);
        }

        return $this->render('moy_sklad/mappings/connections.html.twig', ['pager' => $pager, 'mappingMarketplace' => null, 'pickerRoute' => 'moysklad_stores']);
    }

    #[Route('/moy-sklad/connections/{connectionId}/stores', name: 'moysklad_stores', requirements: ['connectionId' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $connectionId, Request $request, ActiveCompanyService $activeCompany, MoySkladConnectionWriteRepository $connections,
        StoreBalanceQuery $query, BalanceFacade $balance, CompanyFacade $companies, StoreAccountSuggestions $suggestions): Response
    {
        $connectionId = strtolower($connectionId);
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $connection = $connections->findByIdAndCompanyId($connectionId, $companyId) ?? throw $this->createNotFoundException();
        $unassignedOnly = 'unassigned' === $request->query->get('filter');
        $user = $this->getUser();
        // План счетов и суммы Баланса — только при финансовом доступе; без него страница показывает склады и оценку.
        $financeRead = $user instanceof User && null !== $user->getId() && $companies->financialAccess($companyId, $user->getId())['read'];
        if ($financeRead) {
            $balance->ensureStandardChart($companyId); // типовые счета Баланса — чтобы складам было что назначить
        }
        $accounts = $financeRead ? $balance->getPostingAccounts($companyId) : [];
        $accountsById = [];
        foreach ($accounts as $account) {
            $accountsById[$account->id] = $account;
        }
        $run = $query->latestRun($companyId, $connectionId);
        $valued = $query->latestValuedSnapshot($companyId, $connectionId);
        $allStores = $query->stores($companyId, $connectionId, $valued['snapshotId'] ?? null);
        $totals = [];
        foreach ($allStores as $store) {
            if (null !== $store['accountId']) {
                $totals[$store['accountId']] = bcadd($totals[$store['accountId']] ?? '0', $store['amountMinor'] ?? '0', 0);
            }
        }
        $suggested = $financeRead ? $suggestions->forStores($allStores, $accounts) : [];
        $stores = $unassignedOnly ? array_values(array_filter($allStores, static fn (array $store): bool => null === $store['accountId'] && !$store['excluded'])) : $allStores;
        $postings = $financeRead ? $query->latestPostings($companyId, $connectionId) : [];
        if (!$financeRead) {
            $totals = [];
        }

        return $this->render('moy_sklad/stores/index.html.twig', [
            'connection' => $connection,
            'stores' => $stores,
            'suggestions' => $suggested,
            'counts' => $query->counts($companyId, $connectionId),
            'unassignedOnly' => $unassignedOnly,
            'assetAccounts' => array_values(array_filter($accounts, static fn ($account): bool => $account->isAsset)),
            'accounts' => $accounts,
            'accountsById' => $accountsById,
            'totals' => $totals,
            'postings' => array_map(static fn (array $posting): array => $posting + ['label' => StoreBalanceStatus::from($posting['status'])->label(), 'tone' => StoreBalanceStatus::from($posting['status'])->tone()], $postings),
            'run' => null === $run ? null : $run + ['label' => StoreBalanceStatus::from($run['status'])->label(), 'tone' => StoreBalanceStatus::from($run['status'])->tone(), 'error' => StoreBalanceStatus::errorLabel($run['errorReason'])],
            'settings' => $financeRead ? $query->settings($companyId, $connectionId) : null,
            'financeRead' => $financeRead,
            'valuedDate' => $valued['date'] ?? null,
            'book' => $balance->getBookStatus($companyId),
            'csrfId' => self::csrfId($companyId, $connectionId),
        ]);
    }
}

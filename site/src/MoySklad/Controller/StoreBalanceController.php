<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Balance\Facade\BalanceFacade;
use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\Company\Security\ModuleAccess;
use App\MoySklad\Application\DTO\ResolvedStoreSuggestion;
use App\MoySklad\Application\StoreAccountSuggestions;
use App\MoySklad\Enum\StoreBalanceStatus;
use App\MoySklad\Infrastructure\Query\MoySkladConnectionsQuery;
use App\MoySklad\Infrastructure\Query\StoreAccountSuggestionStore;
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
    /** Вкладки таблицы складов; «Требуют решения» — без назначения (с предложением или без). */
    public const FILTERS = ['todo', 'linked', 'excluded', 'all'];
    private const STATUS_FILTER = ['todo' => 'todo', 'suggested' => 'todo', 'unsure' => 'todo', 'linked' => 'linked', 'excluded' => 'excluded'];

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
        StoreBalanceQuery $query, BalanceFacade $balance, CompanyFacade $companies, StoreAccountSuggestions $suggestions, StoreAccountSuggestionStore $answers): Response
    {
        $connectionId = strtolower($connectionId);
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $connection = $connections->findByIdAndCompanyId($connectionId, $companyId) ?? throw $this->createNotFoundException();
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
        $suggested = $financeRead ? $suggestions->forStores($allStores, $accounts, $answers->answers($companyId, $connectionId)) : [];
        $rows = [];
        $counts = array_fill_keys(self::FILTERS, 0);
        foreach ($allStores as $store) {
            $row = self::row($store, $suggested[$store['externalId']] ?? null);
            ++$counts[self::STATUS_FILTER[$row['status']]];
            ++$counts['all'];
            $rows[] = $row;
        }
        $filter = $request->query->get('filter');
        $filter = 'unassigned' === $filter ? 'todo' : $filter;
        $filter = \in_array($filter, self::FILTERS, true) ? $filter : ($counts['todo'] > 0 ? 'todo' : 'all');
        $stores = array_values(array_filter($rows, static fn (array $row): bool => 'all' === $filter || self::STATUS_FILTER[$row['status']] === $filter));
        // Крупные остатки — первыми: их ошибка дороже.
        usort($stores, static fn (array $a, array $b): int => bccomp($b['amountMinor'] ?? '0', $a['amountMinor'] ?? '0', 0) ?: strcmp($a['name'], $b['name']));
        $assetAccounts = array_values(array_filter($accounts, static fn ($account): bool => $account->isAsset));
        $isInventory = static fn ($account): bool => str_starts_with($account->code, 'ACC_INV_');
        $postings = $financeRead ? $query->latestPostings($companyId, $connectionId) : [];
        if (!$financeRead) {
            $totals = [];
        }

        return $this->render('moy_sklad/stores/index.html.twig', [
            'connection' => $connection,
            'stores' => $stores,
            'counts' => $counts,
            'filter' => $filter,
            'accountGroups' => array_filter(['Запасы' => array_filter($assetAccounts, $isInventory), 'Другие счета актива' => array_filter($assetAccounts, static fn ($account): bool => !$isInventory($account))]),
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

    /**
     * Строка таблицы: статус, выбор в списке и состояние на момент показа (`was`) — по нему сохранение не перетирает правку из другого окна.
     * Уверенное предложение подставлено в выбор, «Сохранить» его принимает; неуверенное — только подсказка.
     *
     * @param array{externalId: string, name: string, pathName: string, accountId: ?string, excluded: bool, quantity: ?string, amountMinor: ?string, unpriced: int, missing: int} $store
     *
     * @return array{externalId: string, name: string, pathName: string, accountId: ?string, excluded: bool, quantity: ?string, amountMinor: ?string, unpriced: int, missing: int, status: string, value: string, was: string, suggestion: ?ResolvedStoreSuggestion}
     */
    private static function row(array $store, ?ResolvedStoreSuggestion $suggestion): array
    {
        $was = $store['excluded'] ? 'excluded' : ($store['accountId'] ?? '');
        if ('' !== $was) {
            return $store + ['status' => $store['excluded'] ? 'excluded' : 'linked', 'value' => $was, 'was' => $was, 'suggestion' => null];
        }
        if (null === $suggestion) {
            return $store + ['status' => 'todo', 'value' => '', 'was' => '', 'suggestion' => null];
        }

        return $store + ['status' => $suggestion->confident ? 'suggested' : 'unsure', 'value' => $suggestion->confident ? $suggestion->target : '', 'was' => '', 'suggestion' => $suggestion];
    }
}

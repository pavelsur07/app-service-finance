<?php

declare(strict_types=1);

namespace App\Finance\Application\Action;

use App\Company\Enum\BusinessProfile;
use App\Company\Facade\CompanyFacade;
use App\Finance\Application\Command\ImportPLCategoryTreeCommand;
use App\Finance\Application\DTO\ExtendPLTreeResult;
use App\Finance\Application\DTO\PLCategoryTreeNode;
use App\Finance\Application\DTO\PLFormulaChange;
use App\Finance\Entity\PLTemplateApplication;
use App\Finance\Enum\PLTemplateApplicationSource;
use App\Finance\Exception\PLTemplateProfileMismatchException;
use App\Finance\Exception\PLTreeExtensionException;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use App\Finance\Repository\PLCategoryRepository;
use App\Finance\Repository\PLTemplateApplicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Дополняет дерево ОПиУ компании «Базовый» статьями маркетплейса: те же коды и места, что в
 * маркетплейсном шаблоне, — их ждут шаблоны маппинга затрат и продаж. Выручка маркетплейса входит в
 * «Выручку», его расходы — в EBITDA: формулы REV_TOTAL и EBITDA заменяются формулами расширения.
 *
 * Статьи — тем же импортом в режиме `onlyCreate` (свои статьи компании не меняются, второе дерево и
 * дубли похожих названий не создаются). Формула заменяется, только если она всё ещё шаблонная
 * «Базового»: изменённую пользователем молча не перетираем — отказ целиком. Повтор ничего не делает.
 * Применение записывается с профилем UNIVERSAL: происхождение дерева остаётся «Базовым».
 */
final readonly class ExtendPLTreeWithMarketplaceAction
{
    /** Коды, по которым дерево опознаётся как «Базовое»: их нет в маркетплейсном шаблоне. */
    private const UNIVERSAL_MARKERS = ['GROSS_PROFIT', 'REV_SALES_TOTAL'];

    public function __construct(
        private ImportPLCategoryTreeAction $import,
        private DefaultPLCategoryTreeProvider $templates,
        private PLCategoryRepository $categories,
        private PLTemplateApplicationRepository $applications,
        private CompanyFacade $companies,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $companyId, bool $dryRun): ExtendPLTreeResult
    {
        $nodes = $this->templates->marketplaceExtension();
        $command = new ImportPLCategoryTreeCommand($nodes, $companyId, true, onlyCreate: true);
        $changes = $this->check($companyId, $nodes);
        $preview = ($this->import)($command);
        if ($dryRun) {
            return new ExtendPLTreeResult($preview, $changes);
        }

        $result = $this->em->wrapInTransaction(function () use ($companyId, $nodes): ExtendPLTreeResult {
            $this->em->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:key))', ['key' => 'pl-template:'.$companyId]);
            $changes = $this->check($companyId, $nodes);
            $import = ($this->import)(new ImportPLCategoryTreeCommand($nodes, $companyId, false, onlyCreate: true));
            foreach ($changes as $change) {
                $category = $this->categories->findOneByCompanyIdAndCode($companyId, $change->code);
                $category?->setFormula($change->to);
            }
            if ([] !== $import->created) {
                $this->em->persist(new PLTemplateApplication($companyId, BusinessProfile::UNIVERSAL, $this->templates->marketplaceExtensionVersion(), \count($import->created), PLTemplateApplicationSource::MARKETPLACE_EXTENSION, new \DateTimeImmutable()));
            }
            $this->em->flush();

            return new ExtendPLTreeResult($import, $changes);
        });

        $this->logger->info('P&L tree extended with marketplace lines', [
            'companyId' => $companyId,
            'templateVersion' => $this->templates->marketplaceExtensionVersion(),
            'created' => \count($result->import->created),
            'formulas' => array_map(static fn (PLFormulaChange $c): string => $c->code, $result->formulaChanges),
        ]);

        return $result;
    }

    /**
     * Компания «Базовый» с деревом «Базового»; формулы, которые нужно заменить.
     *
     * @param list<PLCategoryTreeNode> $nodes
     *
     * @return list<PLFormulaChange>
     */
    private function check(string $companyId, array $nodes): array
    {
        if (BusinessProfile::UNIVERSAL !== $this->companies->getBusinessProfile($companyId)) {
            throw PLTreeExtensionException::notUniversal();
        }
        $origin = $this->applications->findLatestByCompanyId($companyId);
        if (null !== $origin && BusinessProfile::UNIVERSAL !== $origin->getProfile()) {
            throw PLTemplateProfileMismatchException::between($origin->getProfile(), BusinessProfile::UNIVERSAL);
        }

        $company = $this->companies->findById($companyId) ?? throw new \DomainException('Целевая компания не найдена.');
        $codes = array_flip($this->categories->findCodesByCompany($company));
        foreach (self::UNIVERSAL_MARKERS as $marker) {
            if (!isset($codes[$marker])) {
                throw PLTreeExtensionException::notUniversalTree();
            }
        }

        $universalFormulas = [];
        foreach ($this->templates->nodes(BusinessProfile::UNIVERSAL) as $node) {
            if (null !== $node->code) {
                $universalFormulas[$node->code] = $node->formula;
            }
        }

        $changes = [];
        foreach ($nodes as $node) {
            if (null === $node->code || self::same($node->formula, $universalFormulas[$node->code] ?? $node->formula)) {
                continue;
            }
            // Из базы, а не из EntityManager: под блокировкой важна текущая формула, а не прочитанная раньше.
            $row = $this->em->getConnection()->fetchAssociative('SELECT name, formula FROM pl_categories WHERE company_id = ? AND code = ?', [$companyId, $node->code]);
            if (false === $row || self::same($row['formula'], $node->formula)) {
                continue;
            }
            if (!self::same($row['formula'], $universalFormulas[$node->code])) {
                throw PLTreeExtensionException::formulaChanged((string) $row['name'], (string) $row['formula'], (string) $universalFormulas[$node->code]);
            }
            $changes[] = new PLFormulaChange($node->code, (string) $row['name'], (string) $row['formula'], (string) $node->formula);
        }

        return $changes;
    }

    private static function same(?string $a, ?string $b): bool
    {
        $normalize = static fn (?string $f): string => (string) preg_replace('/\s+/', '', (string) $f);

        return $normalize($a) === $normalize($b);
    }
}

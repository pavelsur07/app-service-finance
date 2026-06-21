<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Company\Entity\Company;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLExpenseType;
use App\Finance\Enum\PLFlow;
use App\Finance\Enum\PLValueFormat;
use App\Finance\Exception\PnlCategoryResolveException;
use App\Finance\Repository\PLCategoryRepository;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final readonly class PnlCategoryResolver
{
    private const OTHER_INCOME_CODE = 'INGESTION_OTHER_INCOME';
    private const OTHER_EXPENSE_CODE = 'INGESTION_OTHER_EXPENSE';
    private const REFUND_OUT_CODE = 'INGESTION_REFUND_OUT';

    public function __construct(
        private CompanyRepository $companyRepository,
        private PLCategoryRepository $categoryRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function resolve(string $companyId, TransactionType $type, TransactionDirection $direction): string
    {
        $company = $this->companyRepository->findById($companyId);
        if (!$company instanceof Company) {
            throw new PnlCategoryResolveException(sprintf('Company "%s" was not found for P&L category resolution.', $companyId));
        }

        $categoryCode = $this->codeFor($type, $direction);
        $category = $this->findByCode($company, $categoryCode)
            ?? $this->createKnownIngestionCategory($company, $categoryCode)
            ?? $this->findByCode($company, $this->fallbackCode($direction))
            ?? $this->createFallback($company, $direction);

        $categoryId = $category->getId();
        if (null === $categoryId) {
            throw new PnlCategoryResolveException('Resolved P&L category does not have an id.');
        }

        return $categoryId;
    }

    private function codeFor(TransactionType $type, TransactionDirection $direction): string
    {
        if (TransactionDirection::IN === $direction) {
            return match ($type) {
                TransactionType::SALE => 'INGESTION_SALE',
                TransactionType::REFUND => 'INGESTION_REFUND_IN',
                TransactionType::BONUS => 'INGESTION_BONUS',
                default => self::OTHER_INCOME_CODE,
            };
        }

        return match ($type) {
            TransactionType::COMMISSION => 'INGESTION_COMMISSION',
            TransactionType::LOGISTICS => 'INGESTION_LOGISTICS',
            TransactionType::STORAGE => 'INGESTION_STORAGE',
            TransactionType::LAST_MILE => 'INGESTION_LAST_MILE',
            TransactionType::ACCEPTANCE => 'INGESTION_ACCEPTANCE',
            TransactionType::ADVERTISING => 'INGESTION_ADVERTISING',
            TransactionType::PENALTY => 'INGESTION_PENALTY',
            TransactionType::ACQUIRING => 'INGESTION_ACQUIRING',
            TransactionType::TAX => 'INGESTION_TAX',
            TransactionType::FEE => 'INGESTION_FEE',
            TransactionType::REFUND => self::REFUND_OUT_CODE,
            default => self::OTHER_EXPENSE_CODE,
        };
    }

    private function fallbackCode(TransactionDirection $direction): string
    {
        return TransactionDirection::IN === $direction ? self::OTHER_INCOME_CODE : self::OTHER_EXPENSE_CODE;
    }

    private function findByCode(Company $company, string $code): ?PLCategory
    {
        return $this->categoryRepository->findOneBy([
            'company' => $company,
            'code' => $code,
        ]);
    }

    private function createKnownIngestionCategory(Company $company, string $code): ?PLCategory
    {
        if (self::REFUND_OUT_CODE !== $code) {
            return null;
        }

        return $this->createCategory(
            company: $company,
            name: 'Ingestion: возвраты',
            code: self::REFUND_OUT_CODE,
            flow: PLFlow::EXPENSE,
            expenseType: PLExpenseType::VARIABLE,
        );
    }

    private function createFallback(Company $company, TransactionDirection $direction): PLCategory
    {
        return $this->createCategory(
            company: $company,
            name: TransactionDirection::IN === $direction ? 'Ingestion: прочие доходы' : 'Ingestion: прочие расходы',
            code: $this->fallbackCode($direction),
            flow: TransactionDirection::IN === $direction ? PLFlow::INCOME : PLFlow::EXPENSE,
            expenseType: TransactionDirection::IN === $direction ? PLExpenseType::OTHER : PLExpenseType::VARIABLE,
        );
    }

    private function createCategory(
        Company $company,
        string $name,
        string $code,
        PLFlow $flow,
        PLExpenseType $expenseType,
    ): PLCategory {
        $category = new PLCategory(Uuid::uuid7()->toString(), $company);
        $category
            ->setName($name)
            ->setCode($code)
            ->setType(PLCategoryType::LEAF_INPUT)
            ->setFormat(PLValueFormat::MONEY)
            ->setFlow($flow)
            ->setExpenseType($expenseType)
            ->setSortOrder($this->categoryRepository->getNextSortOrder($company, null));

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }
}

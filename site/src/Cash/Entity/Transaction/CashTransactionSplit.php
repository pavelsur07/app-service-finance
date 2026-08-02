<?php

declare(strict_types=1);

namespace App\Cash\Entity\Transaction;

use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Repository\Transaction\CashTransactionSplitRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Строка разбивки транзакции ДДС по категориям.
 *
 * Сумма строк равна сумме транзакции — инвариант проверяется в
 * CashTransaction::replaceSplits(), потому что он касается набора строк, а не одной.
 */
#[ORM\Entity(repositoryClass: CashTransactionSplitRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'cash_transaction_split')]
#[ORM\Index(name: 'idx_cts_company_category', columns: ['company_id', 'cashflow_category_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cts_tx_category', columns: ['cash_transaction_id', 'cashflow_category_id'])]
class CashTransactionSplit
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: CashTransaction::class, inversedBy: 'splits')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CashTransaction $cashTransaction;

    #[ORM\Column(name: 'company_id', type: Types::GUID)]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: CashflowCategory::class)]
    #[ORM\JoinColumn(nullable: false)]
    private CashflowCategory $cashflowCategory;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 16, enumType: CashTransactionSplitSource::class)]
    private CashTransactionSplitSource $source;

    public function __construct(
        CashTransaction $cashTransaction,
        CashflowCategory $cashflowCategory,
        string $amount,
        CashTransactionSplitSource $source,
        ?string $id = null,
    ) {
        $id ??= Uuid::uuid7()->toString();
        Assert::uuid($id);

        $companyId = (string) $cashTransaction->getCompany()->getId();
        Assert::uuid($companyId);
        Assert::same(
            (string) $cashflowCategory->getCompany()->getId(),
            $companyId,
            'Категория ДДС принадлежит другой компании.',
        );

        $this->id = $id;
        $this->cashTransaction = $cashTransaction;
        $this->companyId = $companyId;
        $this->cashflowCategory = $cashflowCategory;
        $this->amount = self::canonicalMoney($amount);
        $this->source = $source;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCashTransaction(): CashTransaction
    {
        return $this->cashTransaction;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getCashflowCategory(): CashflowCategory
    {
        return $this->cashflowCategory;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getSource(): CashTransactionSplitSource
    {
        return $this->source;
    }

    /**
     * Ловит мутацию строки в обход CashTransaction::replaceSplits(): прямой вызов
     * changeAmount() Doctrine сохранил бы молча, разбалансировав агрегат. Проверка стоит
     * на самой строке, потому что менять её будут именно так, и висит на обоих событиях:
     * только на PreUpdate её обходила бы правка новой строки до первого flush.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function assertOwnerStaysBalanced(): void
    {
        $this->cashTransaction->assertSplitsBalanced();
    }

    /**
     * Строку с той же категорией переиспользуют, а не пересоздают: уникальный индекс
     * (cash_transaction_id, cashflow_category_id) не переживает пару DELETE+INSERT
     * в одном flush — Doctrine выполняет вставку раньше удаления.
     *
     * @internal вызывать только из CashTransaction::replaceSplits()
     */
    public function changeAmount(string $amount): self
    {
        $this->amount = self::canonicalMoney($amount);

        return $this;
    }

    /**
     * Происхождение меняется только когда состав осознанно собрал человек — это делает
     * форма разбивки через CashTransaction::composeSplitsManually(). Путь dual-write сюда
     * не приходит: replaceSplits() переиспользует строку с той же категорией и обязан
     * сохранить её прежний source, иначе правка суммы переписывала бы происхождение.
     *
     * @internal вызывать только из CashTransaction::composeSplitsManually()
     */
    public function changeSource(CashTransactionSplitSource $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Проверяет сумму и приводит её к тому же виду, в котором её хранит БД.
     *
     * Формат проверяется регулярным выражением, а не сравнением с округлённым
     * значением: bcmath со scale 2 усекает лишние знаки, а PostgreSQL NUMERIC(18,2)
     * их округляет, поэтому «1.999» прошло бы инвариант как «1.99», а в БД легло бы
     * как «2.00». Сравнение на фиксированном scale ту же дыру оставляет для «1.0000001».
     *
     * Канонизация не косметическая. Из Postgres сумма приходит как «50000.00», а из формы —
     * как «50000». Снимок состава для аудита сравнивается построчно, поэтому без приведения
     * каждое редактирование транзакции выглядело бы как изменение разбивки и писало бы
     * запись в историю о несуществующем изменении — на каждую правку, бесконечно.
     */
    private static function canonicalMoney(string $amount): string
    {
        if (1 !== preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new \DomainException(sprintf('Сумма строки разбивки «%s» должна быть положительным числом не более чем с двумя знаками после запятой.', $amount));
        }

        if (1 !== bccomp($amount, '0', 2)) {
            throw new \DomainException('Сумма строки разбивки должна быть больше нуля.');
        }

        return bcadd($amount, '0', 2);
    }

    /**
     * Снимок для aggregate-аудита: строки живут отдельной сущностью и в changeset
     * транзакции не попадают, поэтому состав пишется в диff явно.
     *
     * @return array{category: string, categoryName: string, amount: string, source: string}
     */
    public function toAuditRow(): array
    {
        return [
            'category' => (string) $this->cashflowCategory->getId(),
            'categoryName' => $this->cashflowCategory->getName(),
            'amount' => $this->amount,
            'source' => $this->source->value,
        ];
    }
}

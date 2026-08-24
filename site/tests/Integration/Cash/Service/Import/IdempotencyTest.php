<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Import;

use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use Ramsey\Uuid\Uuid;

class IdempotencyTest extends ClientBank1CImportServiceTestCase
{
    public function testDistinctDocumentsWithSamePaymentDetailsAreBothImported(): void
    {
        $firstRow = [
            'docType' => 'Банковский ордер',
            'docNumber' => '861307',
            'docDate' => '2026-01-29',
            'amount' => 1099,
            'payerAccount' => '40702810900000000001',
            'receiverAccount' => '40702810900000000002',
            'dateDebit' => '2026-01-29',
            'dateCredit' => null,
            'purpose' => 'Покупка товара с одинаковыми реквизитами',
            'direction' => 'outflow',
        ];
        $secondRow = $firstRow;
        $secondRow['docNumber'] = '924374';
        $rows = [$firstRow, $secondRow];

        $firstSummary = $this->service->import($rows, $this->account, false);

        self::assertSame(2, $firstSummary['created']);
        self::assertSame(0, $firstSummary['duplicates']);
        self::assertSame(0, $firstSummary['errors']);
        $importedTransactions = $this->transactionRepository->findAll();
        self::assertCount(2, $importedTransactions);
        foreach ($importedTransactions as $transaction) {
            self::assertSame($this->systemProject->getId(), $transaction->getProjectDirection()?->getId());
            self::assertSame($this->systemCenter->getId(), $transaction->getResponsibilityCenterId());
        }

        $secondSummary = $this->service->import($rows, $this->account, false);

        self::assertSame(0, $secondSummary['created']);
        self::assertSame(2, $secondSummary['duplicates']);
        self::assertSame(0, $secondSummary['errors']);
        self::assertCount(2, $this->transactionRepository->findAll());
    }

    /**
     * Регрессия: ФНС взыскивает по одному решению несколькими платёжными ордерами,
     * и по правилам они несут номер исходного распоряжения — номер, дата, сумма,
     * счета и назначение совпадают полностью. Раньше вторая такая строка файла
     * схлопывалась в externalId первой и терялась: по июню 2026 так пропало
     * списание на 24 000 ₽, из-за чего остаток по счёту разошёлся с выпиской.
     */
    public function testIdenticalPartialExecutionOrdersInOneFileAreBothImported(): void
    {
        $row = [
            'docType' => 'Платежный ордер',
            'docNumber' => '36341',
            'docDate' => '2026-06-19',
            'amount' => 24000,
            'payerAccount' => '40702810900000000001',
            'receiverAccount' => '03100643000000018500',
            'dateDebit' => '2026-06-19',
            'dateCredit' => null,
            'purpose' => 'По решению о взыскании от 01.06.2026 № 18937 по ст.46 НК РФ',
            'direction' => 'outflow',
        ];
        $rows = [$row, $row];

        $firstSummary = $this->service->import($rows, $this->account, false);

        self::assertSame(2, $firstSummary['created'], 'Оба ордера — реальные списания, второй не дубликат');
        self::assertSame(0, $firstSummary['duplicates']);
        self::assertSame(0, $firstSummary['errors']);
        self::assertCount(2, $this->transactionRepository->findAll());

        $secondSummary = $this->service->import($rows, $this->account, false);

        self::assertSame(0, $secondSummary['created'], 'Повторная загрузка того же файла обязана остаться идемпотентной');
        self::assertSame(2, $secondSummary['duplicates']);
        self::assertCount(2, $this->transactionRepository->findAll());
    }

    /**
     * Первое вхождение обязано сохранить прежний externalId, иначе уже загруженные
     * выписки перестанут дедуплицироваться и задвоятся при повторной загрузке.
     */
    public function testAlreadyImportedRowStillDeduplicatesWhenFileGainsIdenticalSecondRow(): void
    {
        $row = [
            'docType' => 'Платежный ордер',
            'docNumber' => '36341',
            'docDate' => '2026-06-19',
            'amount' => 24000,
            'payerAccount' => '40702810900000000001',
            'receiverAccount' => '03100643000000018500',
            'dateDebit' => '2026-06-19',
            'dateCredit' => null,
            'purpose' => 'По решению о взыскании от 01.06.2026 № 18937 по ст.46 НК РФ',
            'direction' => 'outflow',
        ];

        $this->service->import([$row], $this->account, false);
        $legacyExternalId = $this->transactionRepository->findAll()[0]->getExternalId();

        $summary = $this->service->import([$row, $row], $this->account, false);

        self::assertSame(1, $summary['created'], 'Добираем только недостающее второе списание');
        self::assertSame(1, $summary['duplicates']);

        $externalIds = array_map(
            static fn ($transaction) => $transaction->getExternalId(),
            $this->transactionRepository->findAll(),
        );
        self::assertCount(2, $externalIds);
        self::assertContains($legacyExternalId, $externalIds, 'externalId ранее загруженной операции не должен меняться');
    }

    /**
     * Граница фикса: повтор номера предусмотрен регламентом только для платёжного
     * ордера. У обычного платёжного поручения две одинаковые строки в одном файле —
     * задвоение выгрузки, и схлопывать их надо как раньше.
     */
    public function testIdenticalOrdinaryPaymentsInOneFileAreStillDeduplicated(): void
    {
        $row = [
            'docType' => 'Платежное поручение',
            'docNumber' => '565',
            'docDate' => '2026-06-15',
            'amount' => 6960,
            'payerAccount' => '40702810900000000001',
            'receiverAccount' => '40817810352098093549',
            'dateDebit' => '2026-06-15',
            'dateCredit' => null,
            'purpose' => 'Выплата заработной платы за июнь 2026г. НДС не облагается.',
            'direction' => 'outflow',
        ];

        $summary = $this->service->import([$row, $row], $this->account, false);

        self::assertSame(1, $summary['created']);
        self::assertSame(1, $summary['duplicates']);
        self::assertCount(1, $this->transactionRepository->findAll());
    }

    public function testRepeatedImportCountsDuplicatesAndSupportsOverwrite(): void
    {
        $row = [
            'docType' => 'Платежное поручение',
            'docNumber' => 'INV-1',
            'docDate' => '2024-01-05',
            'amount' => 1500.25,
            'payerName' => 'ООО Плательщик',
            'payerInn' => '7701000000',
            'payerAccount' => '40702810900000000003',
            'receiverName' => 'ООО Получатель',
            'receiverInn' => '7712000000',
            'receiverAccount' => '40702810900000000004',
            'dateDebit' => '2024-01-05',
            'dateCredit' => null,
            'purpose' => 'Оплата по договору',
            'direction' => 'outflow',
        ];

        $summaryFirst = $this->service->import([$row], $this->account, false);
        self::assertSame(1, $summaryFirst['created']);
        self::assertSame(0, $summaryFirst['duplicates']);
        self::assertSame(0, $summaryFirst['errors']);
        $transaction = $this->transactionRepository->findAll()[0];
        self::assertSame($this->systemProject->getId(), $transaction->getProjectDirection()?->getId());
        self::assertSame($this->systemCenter->getId(), $transaction->getResponsibilityCenterId());

        $summarySecond = $this->service->import([$row], $this->account, false);
        self::assertSame(0, $summarySecond['created']);
        self::assertSame(1, $summarySecond['duplicates']);
        self::assertSame(0, $summarySecond['errors']);

        $transaction->setDescription('Устаревшее назначение');
        $alternateProject = new ProjectDirection(
            Uuid::uuid4()->toString(),
            $this->company,
            'Сохранить проект',
        );
        $alternateCenter = new FinancialResponsibilityCenter($this->company->getId(), 'CFO_KEEP', 'Сохранить');
        $this->em->persist($alternateProject);
        $this->em->persist($alternateCenter);
        $this->em->persist(new FinancialResponsibilityCenterProject(
            $this->company->getId(),
            $alternateProject,
            $alternateCenter,
        ));
        $this->em->flush();
        $transaction->setProjectDirection($alternateProject);
        $transaction->setResponsibilityCenterId($alternateCenter->getId());
        $this->em->flush();

        $summaryOverwrite = $this->service->import([$row], $this->account, true);
        self::assertSame(0, $summaryOverwrite['created']);
        self::assertSame(1, $summaryOverwrite['duplicates']);
        self::assertSame(0, $summaryOverwrite['errors']);

        $transactions = $this->transactionRepository->findAll();
        self::assertCount(1, $transactions);
        self::assertSame('Оплата по договору', $transactions[0]->getDescription());
        self::assertSame($alternateProject->getId(), $transactions[0]->getProjectDirection()?->getId());
        self::assertSame($alternateCenter->getId(), $transactions[0]->getResponsibilityCenterId());
    }
}

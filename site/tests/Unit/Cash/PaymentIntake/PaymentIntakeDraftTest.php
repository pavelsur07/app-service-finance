<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\PaymentIntake;

use App\Cash\Entity\PaymentIntake\PaymentIntakeDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class PaymentIntakeDraftTest extends TestCase
{
    public function testSourceEditImmediatelyInvalidatesPaidConfirmation(): void
    {
        $draft = $this->draft();
        $task = Uuid::uuid7()->toString();
        $draft->queue($task);
        $draft->acceptResult($task, ['intent' => 'payments', 'items' => [['ref' => 'p1', 'amount' => '100.00', 'currency' => 'RUB', 'date' => '2026-11-13', 'direction' => 'outflow', 'execution_status' => 'paid', 'description' => 'Оплата', 'text_evidence' => '100', 'amount_evidence' => '100', 'category_id' => null, 'project_id' => null, 'cfo_id' => null, 'questions' => []]], 'questions' => [], 'target_ref' => null]);
        $id = $draft->getItems()[0]['id'];
        $draft->amend(1, [$id => ['paid_confirmed' => true]]);
        $draft->amend(2, [], 'Теперь сумма 200', 'edit-1');

        self::assertFalse($draft->getItems()[0]['paid_confirmed']);
    }

    public function testCorrectionUsesOnlyExplicitStableTarget(): void
    {
        foreach ([true, false] as $hasTarget) {
            $draft = $this->draft();
            $task = Uuid::uuid7()->toString();
            $item = ['ref' => 'p0', 'amount' => '100.00', 'currency' => 'RUB', 'date' => '2026-11-13', 'direction' => 'outflow', 'execution_status' => 'paid', 'description' => 'Оплата', 'text_evidence' => '100', 'amount_evidence' => '100', 'category_id' => null, 'project_id' => null, 'cfo_id' => null, 'questions' => []];
            $second = array_replace($item, ['ref' => 'p1', 'amount' => '200.00']);
            $draft->queue($task);
            $draft->acceptResult($task, ['intent' => 'payments', 'items' => [$item, $second], 'questions' => [], 'target_ref' => null]);
            $targetId = $draft->getItems()[1]['id'];
            $draft->amend(1, [], 'Измени на 300', 'edit-1');
            $newTask = Uuid::uuid7()->toString();
            $draft->queue($newTask);
            $correction = array_replace($item, ['amount' => '300.00']);
            $draft->acceptResult($newTask, ['intent' => 'correction', 'items' => [$correction], 'questions' => $hasTarget ? [] : ['Какую строку изменить?'], 'target_ref' => $hasTarget ? $targetId : null]);

            self::assertSame('100.00', $draft->getItems()[0]['amount']);
            self::assertSame($hasTarget ? '300.00' : '200.00', $draft->getItems()[1]['amount']);
        }
    }

    public function testStaleResultCannotReplaceManualCorrection(): void
    {
        $draft = $this->draft();
        $draft->queue(Uuid::uuid7()->toString());
        $task = $draft->getTaskId();
        $draft->amend(1, [], 'Исправленный текст', 'edit-1');
        self::assertFalse($draft->acceptResult((string) $task, ['intent' => 'payments', 'items' => [], 'questions' => [], 'target_ref' => null]));
        self::assertSame(2, $draft->getRevision());
    }

    public function testExplicitPaidConfirmationRequiredEvenWhenModelSaysPaid(): void
    {
        $draft = $this->draft();
        $task = Uuid::uuid7()->toString();
        $draft->queue($task);
        $draft->acceptResult($task, ['intent' => 'payments', 'items' => [['ref' => 'p1', 'amount' => '100.00', 'currency' => 'RUB', 'date' => '2026-11-13', 'direction' => 'outflow', 'execution_status' => 'paid', 'description' => 'Оплата', 'text_evidence' => 'оплатил 100', 'amount_evidence' => '100', 'category_id' => null, 'project_id' => null, 'cfo_id' => null, 'questions' => []]], 'questions' => [], 'target_ref' => null]);
        $id = $draft->getItems()[0]['id'];
        $this->expectException(\DomainException::class);
        $draft->selectedForConfirmation(1, [$id]);
    }

    public function testSourceCompanyAndActorAreImmutable(): void
    {
        $draft = $this->draft();
        self::assertSame('bot/chat/msg', $draft->getSourceKey());
        self::assertSame('2026-11-13', $draft->getReceivedAt()->format('Y-m-d'));
        self::assertSame('pending', $draft->getStatus());
    }

    public function testFinancialCorrectionClearsPriorPaidConfirmation(): void
    {
        $draft = $this->draft();
        $task = Uuid::uuid7()->toString();
        $draft->queue($task);
        $draft->acceptResult($task, ['intent' => 'payments', 'items' => [['ref' => 'p1', 'amount' => '100.00', 'currency' => 'RUB', 'date' => '2026-11-13', 'direction' => 'outflow', 'execution_status' => 'paid', 'description' => 'Оплата', 'text_evidence' => 'оплатил 100', 'amount_evidence' => '100', 'category_id' => null, 'project_id' => null, 'cfo_id' => null, 'questions' => []]], 'questions' => [], 'target_ref' => null]);
        $id = $draft->getItems()[0]['id'];
        $draft->amend(1, [$id => ['paid_confirmed' => true]]);
        self::assertSame('ready', $draft->getStatus());
        $draft->amend(2, [$id => ['amount' => '200.00']]);
        self::assertFalse($draft->getItems()[0]['paid_confirmed']);
    }

    public function testReparsedPaymentKeepsStableIdentityAndManualFields(): void
    {
        $draft = $this->draft();
        $task = Uuid::uuid7()->toString();
        $item = ['ref' => 'p1', 'amount' => '100.00', 'currency' => 'RUB', 'date' => '2026-11-13', 'direction' => 'outflow', 'execution_status' => 'paid', 'description' => 'Оплата', 'text_evidence' => 'оплатил 100', 'amount_evidence' => '100', 'category_id' => null, 'project_id' => null, 'cfo_id' => null, 'questions' => []];
        $draft->queue($task);
        $draft->acceptResult($task, ['intent' => 'payments', 'items' => [$item], 'questions' => [], 'target_ref' => null]);
        $id = $draft->getItems()[0]['id'];
        $draft->amend(1, [$id => ['amount' => '200.00', 'paid_confirmed' => true]]);
        $draft->amend(2, [], 'оплатил 300', 'edit-1');
        $newTask = Uuid::uuid7()->toString();
        $draft->queue($newTask);
        $item['ref'] = $id;
        $item['amount'] = '300.00';
        $draft->acceptResult($newTask, ['intent' => 'payments', 'items' => [$item], 'questions' => [], 'target_ref' => null]);
        self::assertSame($id, $draft->getItems()[0]['id']);
        self::assertSame('200.00', $draft->getItems()[0]['amount']);
        self::assertSame('user', $draft->getItems()[0]['provenance']['amount']);
        self::assertFalse($draft->getItems()[0]['paid_confirmed']);
        self::assertNotEmpty($draft->getItems()[0]['questions']);
    }

    public function testOlderSourceEditCannotReplaceNewerTextEvenWithLatestDraftRevision(): void
    {
        $draft = $this->draft();
        self::assertTrue($draft->amend(1, [], 'Новое сообщение 200', 'edit:200:20:bb'));
        self::assertFalse($draft->amend(2, [], 'Запоздалое сообщение 100', 'edit:100:30:aa'));
        self::assertFalse($draft->amend(2, [], 'Старый update той же секунды', 'edit:200:19:cc'));
        self::assertSame('Новое сообщение 200', $draft->getText());
        self::assertSame(2, $draft->getRevision());
    }

    public function testPatchAndTextTogetherAreRejectedInsteadOfDroppingThePatch(): void
    {
        $draft = $this->draftWithItem();
        $this->expectException(\DomainException::class);
        $draft->amend(1, [$draft->getItems()[0]['id'] => ['amount' => '2.00']], 'новый текст', 'edit-new');
    }

    public function testTopLevelQuestionLengthIsBounded(): void
    {
        $draft = $this->draft();
        $this->expectException(\DomainException::class);
        $draft->amend(1, [], questions: [str_repeat('я', 501)]);
    }

    #[DataProvider('invalidPatches')]
    public function testPatchValuesAreBounded(string $field, mixed $value): void
    {
        $draft = $this->draftWithItem();
        $this->expectException(\DomainException::class);
        $draft->amend(1, [$draft->getItems()[0]['id'] => [$field => $value]]);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidPatches(): iterable
    {
        yield 'description' => ['description', str_repeat('я', 1025)];
        yield 'question' => ['questions', [str_repeat('я', 501)]];
        yield 'direction' => ['direction', 'sideways'];
        yield 'execution' => ['execution_status', 'maybe'];
        yield 'amount' => ['amount', 'INF'];
        yield 'date' => ['date', '2026-02-31'];
        yield 'currency' => ['currency', 'RUBBLE'];
        yield 'identifier' => ['project_id', 'not-a-uuid'];
    }

    public function testExcludedRowsDoNotReceiveGeneralDefaultsOrRepeatedQuestions(): void
    {
        $draft = $this->draftWithItem();
        $draft->amend(1, [$draft->getItems()[0]['id'] => ['excluded' => true]]);
        $draft->applyGeneralPairDefault(null, null);
        self::assertSame([], $draft->getItems()[0]['questions']);
    }

    public function testSnapshotSurvivesDispatchAndIsReleasedAfterConsumingResult(): void
    {
        $draft = $this->draft();
        $task = Uuid::uuid7()->toString();
        $snapshot = ['text' => 'оплатил 100', 'categories' => [['id' => Uuid::uuid7()->toString(), 'name' => 'Статья', 'direction' => 'any']]];
        $draft->queue($task, $snapshot);
        $draft->markDispatched($task);
        self::assertSame($snapshot, $draft->getPayload());
        $draft->acceptResult($task, ['intent' => 'no_payment', 'items' => [], 'questions' => [], 'target_ref' => null]);
        self::assertSame([], $draft->getPayload());
    }

    public function testMissingGeneralPairQuestionIsNotRepeated(): void
    {
        $draft = $this->draftWithItem();
        $draft->applyGeneralPairDefault(null, null);
        $draft->applyGeneralPairDefault(null, null);
        self::assertSame(['Укажите проект и ЦФО.'], $draft->getItems()[0]['questions']);
    }

    private function draftWithItem(): PaymentIntakeDraft
    {
        $draft = $this->draft();
        $task = Uuid::uuid7()->toString();
        $draft->queue($task, ['text' => 'оплатил 100']);
        $draft->acceptResult($task, ['intent' => 'payments', 'items' => [['ref' => 'p1', 'amount' => '100.00', 'currency' => 'RUB', 'date' => '2026-11-13', 'direction' => 'outflow', 'execution_status' => 'paid', 'description' => 'Оплата', 'text_evidence' => 'оплатил 100', 'amount_evidence' => '100', 'category_id' => null, 'project_id' => null, 'cfo_id' => null, 'questions' => []]], 'questions' => [], 'target_ref' => null]);

        return $draft;
    }

    private function draft(): PaymentIntakeDraft
    {
        return new PaymentIntakeDraft(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), 'bot/chat/msg', 'original', 'оплатил 100', new \DateTimeImmutable('2026-11-13T12:00:00+03:00'), 'Europe/Moscow', 'RUB');
    }
}

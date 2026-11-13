<?php

declare(strict_types=1);

namespace App\Tests\Functional\Telegram;

use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\TelegramBot;
use App\Telegram\Entity\TelegramUser;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TelegramPaymentIntakeTest extends WebTestCaseBase
{
    private const COMPANY = '11111111-1111-1111-1111-abcd0000ee01';
    /** @var list<array<string, mixed>> */
    private array $sent = [];
    private bool $failNextDelivery = false;
    private bool $aiGranted = true;
    private bool $replaceLeaseOnDelivery = false;
    private ?\Closure $duringDelivery = null;
    /** @var list<string> */
    private array $aiTaskKeys = [];

    public function testConfiguredCompanyWithDisabledServiceCannotFallBackToLegacy(): void
    {
        $client = $this->setupClient(true);
        $this->post($client, ['message' => $this->message('+1500 доход')]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('недоступен', implode("\n", array_column($this->sent, 'text')));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = ?', [self::COMPANY]));
    }

    public function testOldBindingRequiresActualActorRebindWithoutImpersonatingOwner(): void
    {
        $client = $this->setupClient(false);
        $this->post($client, ['message' => $this->message('+1500 доход')]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('обновите привязку', implode("\n", array_column($this->sent, 'text')));
    }

    public function testEditWithNoOriginalDraftDoesNotInvokeLegacy(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['edited_message' => $this->message('+1500 доход')]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Исходный черновик не найден', implode("\n", array_column($this->sent, 'text')));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = ?', [self::COMPANY]));
    }

    public function testOrdinaryTextCreatesOnePinnedDraftAndDuplicateDoesNotStartSecondPoll(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Сообщение принято', implode("\n", array_column($this->sent, 'text')));
        $transport = $client->getContainer()->get('messenger.transport.async_payment_intake');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        self::assertCount(1, array_filter($transport->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof \App\Telegram\Message\RefreshPaymentIntakeMessage));
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_payment_intake_draft WHERE company_id = ?', [self::COMPANY]));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM telegram_payment_intake_session WHERE company_id = ?', [self::COMPANY]));
        $transport = $client->getContainer()->get('messenger.transport.async_payment_intake');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $polls = array_filter($transport->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof \App\Telegram\Message\RefreshPaymentIntakeMessage);
        self::assertCount(0, $polls, 'Second webhook must not schedule a second polling chain.');
    }

    public function testPollDeliversPlainProcessingCardAndSchedulesShortDelay(): void
    {
        $client = $this->setupClient(true, true);
        $results = $this->createStub(\App\ServiceGateway\Facade\AiTaskResultFacadeInterface::class);
        $results->method('refreshTask')->willReturn(false);
        $client->getContainer()->set(\App\ServiceGateway\Facade\AiTaskResultFacadeInterface::class, $results);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $transport = $client->getContainer()->get('messenger.transport.async_payment_intake');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $polls = array_values(array_filter($transport->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof \App\Telegram\Message\RefreshPaymentIntakeMessage));
        self::assertCount(1, $polls);
        $handler = $client->getContainer()->get(\App\Telegram\MessageHandler\RefreshPaymentIntakeMessageHandler::class);
        $pollMessage = $polls[0]->getMessage();
        self::assertInstanceOf(\App\Telegram\Message\RefreshPaymentIntakeMessage::class, $pollMessage);
        $handler($pollMessage);
        self::assertStringContainsString('операции появятся только после подтверждения', implode("\n", array_column($this->sent, 'text')));
        $last = $transport->getSent()[count($transport->getSent()) - 1];
        $delay = $last->last(\Symfony\Component\Messenger\Stamp\DelayStamp::class);
        self::assertInstanceOf(\Symfony\Component\Messenger\Stamp\DelayStamp::class, $delay);
        self::assertSame(2000, $delay->getDelay());
        $nextMessage = $last->getMessage();
        self::assertInstanceOf(\App\Telegram\Message\RefreshPaymentIntakeMessage::class, $nextMessage);
        self::assertSame(1, $nextMessage->attempt);
    }

    public function testSelectedCompanyChangeCannotMoveOriginalMessageOrRepeatedEdit(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        // Recover Cash-committed source identity even if its Telegram delivery session was lost.
        $this->em()->getConnection()->executeStatement('DELETE FROM telegram_payment_intake_session WHERE company_id = ?', [self::COMPANY]);
        $this->em()->clear();
        $binding = $this->em()->getRepository(ClientBinding::class)->findOneBy(['company' => self::COMPANY]);
        self::assertInstanceOf(ClientBinding::class, $binding);
        $owner = $binding->getCompany()->getUser();
        self::assertInstanceOf(\App\Company\Entity\User::class, $owner);
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-abcd0000ee02')->withOwner($owner)->withName('Вторая компания')->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->withId('33333333-3333-3333-3333-abcd0000ee02')->forCompany($company)->build();
        $second = new ClientBinding(Uuid::uuid7()->toString(), $company, $binding->getBot(), $binding->getTelegramUser());
        $second->setActorUserId($binding->getActorUserId());
        $second->setMoneyAccount($account);
        foreach ([$company, $account, $second] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->post($client, ['message' => array_replace($this->message('/set_cash '.$account->getId()), ['message_id' => 56])]);
        self::assertTrue($this->em()->getConnection()->fetchOne('SELECT selected FROM client_bindings WHERE id = ?', [$second->getId()]));
        $edit = $this->message('Оплатил рекламу 2000') + ['edit_date' => 1794575000];
        $this->post($client, ['edited_message' => $edit]);
        $this->post($client, ['edited_message' => $edit]);
        $olderEdit = array_replace($edit, ['edit_date' => 1794574999, 'text' => 'Оплатил рекламу 9999']);
        $this->post($client, ['edited_message' => $olderEdit]);
        self::assertStringContainsString('Более новая версия', $this->lastText());
        $row = $this->em()->getConnection()->fetchAssociative('SELECT company_id, revision FROM cash_payment_intake_draft WHERE company_id = ?', [self::COMPANY]);
        self::assertIsArray($row);
        self::assertSame(self::COMPANY, $row['company_id']);
        self::assertSame(2, $row['revision']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_payment_intake_draft WHERE company_id = ?', [self::COMPANY]));
        $this->em()->getConnection()->executeStatement("UPDATE telegram_payment_intake_session SET reply_message_ids = '[\"900\"]'::json WHERE company_id = ?", [self::COMPANY]);
        $this->em()->getConnection()->executeStatement("UPDATE client_bindings SET status = 'blocked' WHERE company_id = ?", [self::COMPANY]);
        $this->em()->clear();
        $beforeReply = count($this->aiTaskKeys);
        $reply = array_replace($this->message('сумма 3000'), ['message_id' => 77, 'reply_to_message' => ['message_id' => 900]]);
        $this->post($client, ['message' => $reply]);
        self::assertCount($beforeReply, $this->aiTaskKeys, 'Revoked source reply must not become a new parse in the selected second company.');
        self::assertStringContainsString('недоступен', $this->lastText());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_payment_intake_draft WHERE company_id = ?', ['11111111-1111-1111-1111-abcd0000ee02']));
        $this->em()->getConnection()->executeStatement('DELETE FROM client_bindings WHERE company_id = ?', [self::COMPANY]);
        $this->em()->clear();
        $container = $client->getContainer();
        $disabledAction = new \App\Telegram\Application\PaymentIntakeTelegramAction($this->em(), $container->get(\App\Cash\Facade\CashFacade::class), $container->get(\App\Company\Facade\CompanyFacade::class), $container->get(\App\Company\Facade\FinancialResponsibilityCenterFacade::class), $container->get(AiTaskFacadeInterface::class), $container->get(\App\Telegram\Application\QueuePaymentIntakePollAction::class), false);
        $replay = $disabledAction->message($binding->getBot(), $this->message('+1500 доход'), '+1500 доход');
        self::assertIsArray($replay, 'Known Cash intake source must never fall through to legacy after its binding/session was removed.');
    }

    public function testForeignCallbackAndRevokedBindingCannotConfirm(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $session = $this->em()->getRepository(\App\Telegram\Entity\PaymentIntakeSession::class)->findOneBy(['companyId' => self::COMPANY]);
        self::assertInstanceOf(\App\Telegram\Entity\PaymentIntakeSession::class, $session);
        $callback = ['id' => 'callback-1', 'data' => 'pi:'.\App\Telegram\Application\PaymentIntakePresenter::encodeId($session->getId()).':1:confirm', 'from' => ['id' => 70091], 'message' => ['chat' => ['id' => 70090]]];
        $this->post($client, ['callback_query' => $callback]);
        self::assertStringContainsString('недоступен', $this->lastText());
        $this->em()->getConnection()->executeStatement("UPDATE client_bindings SET status = 'blocked' WHERE id = ?", [$session->getBinding()->getId()]);
        $this->em()->clear();
        $callback['from']['id'] = 70090;
        $this->post($client, ['callback_query' => $callback]);
        self::assertStringContainsString('недоступен', $this->lastText());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = ?', [self::COMPANY]));
    }

    public function testConfirmedMoneySurvivesTelegramTimeoutAndNotificationRetry(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $transport = $client->getContainer()->get('messenger.transport.async_payment_intake');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $poll = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(\App\Telegram\Message\RefreshPaymentIntakeMessage::class, $poll);
        $session = $this->em()->getRepository(\App\Telegram\Entity\PaymentIntakeSession::class)->findOneBy(['companyId' => self::COMPANY]);
        self::assertInstanceOf(\App\Telegram\Entity\PaymentIntakeSession::class, $session);
        $itemId = Uuid::uuid7()->toString();
        $item = ['id' => $itemId, 'transaction_id' => Uuid::uuid7()->toString(), 'amount' => '1500.00', 'currency' => 'RUB', 'date' => '2026-11-13', 'direction' => 'outflow', 'description' => 'Реклама', 'category_id' => null, 'project_id' => null, 'cfo_id' => null, 'execution_status' => 'paid', 'paid_confirmed' => false, 'questions' => [], 'provenance' => []];
        $this->em()->getConnection()->executeStatement("UPDATE cash_payment_intake_draft SET status = 'needs_clarification', items = CAST(? AS JSONB) WHERE id = ?", [json_encode([$item], \JSON_THROW_ON_ERROR), $session->getDraftId()]);
        $prefix = 'pi:'.\App\Telegram\Application\PaymentIntakePresenter::encodeId($session->getId()).':';
        $callback = ['id' => 'confirm-1', 'from' => ['id' => 70090], 'message' => ['chat' => ['id' => 70090]], 'data' => $prefix.'1:q'.\App\Telegram\Application\PaymentIntakePresenter::encodeId($itemId)];
        $this->post($client, ['callback_query' => $callback]);
        $this->em()->getConnection()->executeStatement("UPDATE telegram_payment_intake_session SET delivery_pending = false, delivered_state = '2:ready' WHERE company_id = ?", [self::COMPANY]);
        $deliveryIntentAtMoneyCommit = null;
        $client->getContainer()->get('event_dispatcher')->addListener(\App\Cash\Domain\Event\CashTransactionsChanged::class, function () use (&$deliveryIntentAtMoneyCommit): void {
            $deliveryIntentAtMoneyCommit = $this->em()->getConnection()->fetchOne('SELECT delivery_pending FROM telegram_payment_intake_session WHERE company_id = ?', [self::COMPANY]);
        });
        $callback['data'] = $prefix.'2:confirm';
        $this->post($client, ['callback_query' => $callback]);
        self::assertTrue($deliveryIntentAtMoneyCommit, 'Delivery intent must already exist when money commits, before Telegram acknowledgement.');
        self::assertStringContainsString('Платежи сохранены', $this->lastText());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = ?', [self::COMPANY]));
        $this->failNextDelivery = true;
        $handler = $client->getContainer()->get(\App\Telegram\MessageHandler\RefreshPaymentIntakeMessageHandler::class);
        try {
            $handler($poll);
            self::fail('Delivery must fail once.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('delivery transport failed', $exception->getMessage());
            self::assertStringNotContainsString('bot token URL', $exception->getMessage());
        }
        $handler($poll);
        $this->post($client, ['callback_query' => $callback]);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = ?', [self::COMPANY]));
    }

    public function testPartialDeliveryResumesNextCardAfterFailureAndIgnoresOldDeliveryStep(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $transport = $client->getContainer()->get('messenger.transport.async_payment_intake');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $poll = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(\App\Telegram\Message\RefreshPaymentIntakeMessage::class, $poll);
        $items = [];
        for ($i = 0; $i < 20; ++$i) {
            $items[] = ['id' => Uuid::uuid7()->toString(), 'transaction_id' => Uuid::uuid7()->toString(), 'amount' => '100.00', 'currency' => 'RUB', 'date' => '2026-11-13', 'direction' => 'outflow', 'execution_status' => 'paid', 'paid_confirmed' => false, 'category_id' => null, 'project_id' => null, 'cfo_id' => null, 'description' => 'Расход '.$i, 'questions' => [], 'provenance' => []];
        }
        $this->em()->getConnection()->executeStatement("UPDATE cash_payment_intake_draft SET status = 'needs_clarification', items = CAST(? AS JSONB) WHERE company_id = ?", [json_encode($items, \JSON_THROW_ON_ERROR), self::COMPANY]);
        $handler = $client->getContainer()->get(\App\Telegram\MessageHandler\RefreshPaymentIntakeMessageHandler::class);
        $handler($poll);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT delivery_cursor FROM telegram_payment_intake_session WHERE company_id = ?', [self::COMPANY]));
        $sentCount = count($this->sent);
        $handler($poll);
        self::assertCount($sentCount, $this->sent, 'Replayed delivery step must not send another card.');
        $envelopes = $transport->getSent();
        $next = $envelopes[count($envelopes) - 1]->getMessage();
        self::assertInstanceOf(\App\Telegram\Message\RefreshPaymentIntakeMessage::class, $next);
        $this->failNextDelivery = true;
        try {
            $handler($next);
            self::fail('Delivery must fail once.');
        } catch (\RuntimeException) {
            self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT delivery_cursor FROM telegram_payment_intake_session WHERE company_id = ?', [self::COMPANY]));
        }
        $handler($next);
        self::assertStringContainsString('Строка 1', $this->lastText());
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT delivery_cursor FROM telegram_payment_intake_session WHERE company_id = ?', [self::COMPANY]));
    }

    public function testRevokingAiGrantAfterIntakeCannotReactivateLegacyParser(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $this->aiGranted = false;
        $this->post($client, ['message' => array_replace($this->message('+1500 доход'), ['message_id' => 56])]);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = ?', [self::COMPANY]));
        self::assertStringContainsString('недоступен', $this->lastText());
    }

    public function testBrokerFailureAfterProcessingCardCanRetrySuccessor(): void
    {
        $client = $this->setupClient(true, true);
        $results = $this->createStub(\App\ServiceGateway\Facade\AiTaskResultFacadeInterface::class);
        $results->method('refreshTask')->willReturn(false);
        $client->getContainer()->set(\App\ServiceGateway\Facade\AiTaskResultFacadeInterface::class, $results);
        $queued = [];
        $failOnce = true;
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message, array $stamps = []) use (&$queued, &$failOnce): \Symfony\Component\Messenger\Envelope {
            if ($message instanceof \App\Telegram\Message\RefreshPaymentIntakeMessage && 1 === $message->sequence && $failOnce) {
                $failOnce = false;
                throw new \RuntimeException('Test broker unavailable');
            }
            $queued[] = $message;

            return new \Symfony\Component\Messenger\Envelope($message, $stamps);
        });
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $transport = $client->getContainer()->get('messenger.transport.async_payment_intake');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $poll = $transport->getSent()[0]->getMessage();
        $queued[] = $poll;
        self::assertInstanceOf(\App\Telegram\Message\RefreshPaymentIntakeMessage::class, $poll);
        $container = $client->getContainer();
        $handler = new \App\Telegram\MessageHandler\RefreshPaymentIntakeMessageHandler($this->em(), $container->get(\App\Cash\Facade\CashFacade::class), $container->get(\App\Company\Facade\CompanyFacade::class), $container->get(\App\Company\Facade\FinancialResponsibilityCenterFacade::class), $results, $container->get(\App\Telegram\Application\PaymentIntakePresenter::class), $container->get('http_client'), new \App\Telegram\Application\QueuePaymentIntakePollAction($this->em(), $bus), $container->get('monolog.logger.telegram'));
        try {
            $handler($poll);
            self::fail('Successor enqueue must fail once.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Test broker unavailable', $exception->getMessage());
        }
        $sent = count($this->sent);
        $handler($poll);
        self::assertCount(2, $queued, 'Replayed processed step must restore its missing successor.');
        self::assertCount($sent, $this->sent, 'Restoring successor must not resend the processing card.');
        self::assertInstanceOf(\App\Telegram\Message\RefreshPaymentIntakeMessage::class, $queued[1]);
        self::assertSame(1, $queued[1]->sequence);
    }

    public function testRecoveryRestartsExpiredPollAfterProcessingCardWasDelivered(): void
    {
        $client = $this->setupClient(true, true);
        $results = $this->createStub(\App\ServiceGateway\Facade\AiTaskResultFacadeInterface::class);
        $results->method('refreshTask')->willReturn(false);
        $client->getContainer()->set(\App\ServiceGateway\Facade\AiTaskResultFacadeInterface::class, $results);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $transport = $client->getContainer()->get('messenger.transport.async_payment_intake');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $poll = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(\App\Telegram\Message\RefreshPaymentIntakeMessage::class, $poll);
        $handler = $client->getContainer()->get(\App\Telegram\MessageHandler\RefreshPaymentIntakeMessageHandler::class);
        $handler($poll);
        $this->em()->getConnection()->executeStatement("UPDATE telegram_payment_intake_session SET poll_until = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE company_id = ?", [self::COMPANY]);
        $this->em()->clear();
        $before = count($transport->getSent());
        $command = $client->getContainer()->get(\App\Telegram\Command\RecoverPaymentIntakeDeliveryCommand::class);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(0, $tester->execute(['company-id' => self::COMPANY]));
        self::assertCount($before + 1, $transport->getSent(), 'Expired pending polling needs recovery even when its processing card was delivered.');
    }

    public function testProjectAndCenterCanMoveAtomicallyBetweenDisjointAllowedPairs(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $session = $this->em()->getRepository(\App\Telegram\Entity\PaymentIntakeSession::class)->findOneBy(['companyId' => self::COMPANY]);
        self::assertInstanceOf(\App\Telegram\Entity\PaymentIntakeSession::class, $session);
        $company = $session->getBinding()->getCompany();
        $oldProject = $this->em()->getRepository(\App\Company\Entity\ProjectDirection::class)->findOneBy(['company' => $company]);
        $oldCenter = $this->em()->getRepository(\App\Company\Entity\FinancialResponsibilityCenter::class)->findOneBy(['companyId' => self::COMPANY]);
        self::assertInstanceOf(\App\Company\Entity\ProjectDirection::class, $oldProject);
        self::assertInstanceOf(\App\Company\Entity\FinancialResponsibilityCenter::class, $oldCenter);
        $project = new \App\Company\Entity\ProjectDirection(Uuid::uuid7()->toString(), $company, 'Новый проект');
        $center = new \App\Company\Entity\FinancialResponsibilityCenter(self::COMPANY, 'NEW_CENTER', 'Новый ЦФО');
        $pair = new \App\Company\Entity\FinancialResponsibilityCenterProject(self::COMPANY, $project, $center);
        foreach ([$project, $center, $pair] as $entity) {
            $this->em()->persist($entity);
        }
        $session->markDelivered('1:needs_clarification', ['900']);
        $this->em()->flush();
        $itemId = Uuid::uuid7()->toString();
        $item = ['id' => $itemId, 'transaction_id' => Uuid::uuid7()->toString(), 'amount' => '1500.00', 'currency' => 'RUB', 'date' => '2026-11-13', 'direction' => 'outflow', 'description' => 'Реклама', 'category_id' => null, 'project_id' => $oldProject->getId(), 'cfo_id' => $oldCenter->getId(), 'execution_status' => 'paid', 'paid_confirmed' => false, 'questions' => [], 'provenance' => []];
        $this->em()->getConnection()->executeStatement("UPDATE cash_payment_intake_draft SET status = 'needs_clarification', items = CAST(? AS JSONB) WHERE id = ?", [json_encode([$item], \JSON_THROW_ON_ERROR), $session->getDraftId()]);
        $callback = ['id' => 'edit-pair', 'from' => ['id' => 70090], 'message' => ['chat' => ['id' => 70090]], 'data' => 'pi:'.\App\Telegram\Application\PaymentIntakePresenter::encodeId($session->getId()).':1:e'.\App\Telegram\Application\PaymentIntakePresenter::encodeId($itemId)];
        $this->post($client, ['callback_query' => $callback]);
        $this->seedSessionHistory(200, false);
        $reply = array_replace($this->message('проект Новый проект; ЦФО Новый ЦФО'), ['message_id' => 56, 'reply_to_message' => ['message_id' => 900]]);
        $this->post($client, ['message' => $reply]);
        $view = $client->getContainer()->get(\App\Cash\Facade\CashFacade::class)->getPaymentIntakeDraft(self::COMPANY, '22222222-2222-2222-2222-abcd0000ee01', $session->getDraftId());
        self::assertLessThan(50, $this->em()->getUnitOfWork()->size(), 'Reply lookup must not hydrate the chat history.');
        self::assertSame($project->getId(), $view->items[0]['project_id']);
        self::assertSame($center->getId(), $view->items[0]['cfo_id']);
        self::assertSame(2, $view->revision);
    }

    public function testInitialPollIntentSurvivesBrokerFailureAndRecovery(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $session = $this->em()->getRepository(\App\Telegram\Entity\PaymentIntakeSession::class)->findOneBy(['companyId' => self::COMPANY]);
        self::assertInstanceOf(\App\Telegram\Entity\PaymentIntakeSession::class, $session);
        $session->finishPolling();
        $generation = $session->schedulePolling();
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement('UPDATE cash_payment_intake_draft SET dispatch_pending = true WHERE company_id = ?', [self::COMPANY]);
        $failOnce = true;
        $queued = [];
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message, array $stamps = []) use (&$failOnce, &$queued): \Symfony\Component\Messenger\Envelope {
            if ($failOnce) {
                $failOnce = false;
                throw new \RuntimeException('Initial test broker failure');
            }
            $queued[] = $message;

            return new \Symfony\Component\Messenger\Envelope($message, $stamps);
        });
        $polls = new \App\Telegram\Application\QueuePaymentIntakePollAction($this->em(), $bus);
        try {
            $polls($session);
            self::fail('Initial publication must fail once.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Initial test broker failure', $exception->getMessage());
        }
        self::assertNotNull($session->pendingPoll());
        $container = $client->getContainer();
        $command = new \App\Telegram\Command\RecoverPaymentIntakeDeliveryCommand($this->em(), $container->get(\App\Cash\Facade\CashFacade::class), $container->get(\App\Company\Facade\CompanyFacade::class), $polls, $container->get('monolog.logger.telegram'));
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(0, $tester->execute(['company-id' => self::COMPANY]));
        self::assertCount(1, $queued);
        self::assertInstanceOf(\App\Telegram\Message\RefreshPaymentIntakeMessage::class, $queued[0]);
        self::assertSame($generation, $queued[0]->generation);
        self::assertSame(0, $queued[0]->sequence);
        self::assertFalse($this->em()->getConnection()->fetchOne('SELECT dispatch_pending FROM cash_payment_intake_draft WHERE company_id = ?', [self::COMPANY]));
        self::assertNull($session->pendingPoll());
    }

    public function testDisabledRolloutPreservesLegacyForAiGrantedNeverSelectedBinding(): void
    {
        $client = $this->setupClient(true, true, false);
        $this->post($client, ['message' => $this->message('+1500 доход')]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = ?', [self::COMPANY]));
        self::assertStringContainsString('Записал', $this->lastText());
    }

    public function testDisabledRolloutKeepsPreviouslySelectedBindingClosed(): void
    {
        $client = $this->setupClient(true, true, false);
        $this->em()->getConnection()->executeStatement('UPDATE client_bindings SET payment_intake_selected = true WHERE company_id = ?', [self::COMPANY]);
        $this->em()->clear();
        $this->post($client, ['message' => $this->message('+1500 доход')]);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = ?', [self::COMPANY]));
        self::assertStringContainsString('не включён', $this->lastText());
    }

    public function testUnknownEditedMessageRemainsIgnored(): void
    {
        $client = $this->setupClient(true, true);
        $edited = array_replace($this->message('+1500 доход'), ['from' => ['id' => 79999], 'chat' => ['id' => 79999, 'type' => 'private']]);
        $this->post($client, ['edited_message' => $edited]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->sent);
        self::assertSame('ignored', json_decode((string) $client->getResponse()->getContent(), true)['status']);
    }

    public function testFinishingOldDeliveryCannotClearReplacementLease(): void
    {
        $client = $this->setupClient(true, true);
        $results = $this->createStub(\App\ServiceGateway\Facade\AiTaskResultFacadeInterface::class);
        $results->method('refreshTask')->willReturn(false);
        $client->getContainer()->set(\App\ServiceGateway\Facade\AiTaskResultFacadeInterface::class, $results);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $transport = $client->getContainer()->get('messenger.transport.async_payment_intake');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $poll = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(\App\Telegram\Message\RefreshPaymentIntakeMessage::class, $poll);
        $this->replaceLeaseOnDelivery = true;
        $handler = $client->getContainer()->get(\App\Telegram\MessageHandler\RefreshPaymentIntakeMessageHandler::class);
        $handler($poll);
        self::assertNotNull($this->em()->getConnection()->fetchOne('SELECT lease_until FROM telegram_payment_intake_session WHERE company_id = ?', [self::COMPANY]));
    }

    public function testRecoverySkipsMissingDraftsAcrossCandidateBatchesAndReachesLaterHealthySession(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $this->seedSessionHistory(101, true);
        $this->seedSessionHistory(1, false, 200);
        $this->em()->getConnection()->executeStatement("UPDATE telegram_payment_intake_session SET poll_until = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE company_id = ? AND message_id = '55'", [self::COMPANY]);
        $this->em()->clear();
        $transport = $client->getContainer()->get('messenger.transport.async_payment_intake');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $before = count($transport->getSent());
        $command = $client->getContainer()->get(\App\Telegram\Command\RecoverPaymentIntakeDeliveryCommand::class);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(0, $tester->execute(['company-id' => self::COMPANY]));
        self::assertCount($before + 1, $transport->getSent());
        self::assertStringContainsString('Queued notifications: 1', $tester->getDisplay());
    }

    public function testNewIngressDoesNotRecoverOtherPendingDraftsInCompany(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $firstId = $this->em()->getConnection()->fetchOne('SELECT id FROM cash_payment_intake_draft WHERE company_id = ?', [self::COMPANY]);
        $this->em()->getConnection()->executeStatement('UPDATE cash_payment_intake_draft SET dispatch_pending = true WHERE id = ?', [$firstId]);
        $before = count($this->aiTaskKeys);
        $this->post($client, ['message' => array_replace($this->message('Оплатил доставку 500'), ['message_id' => 56])]);
        self::assertCount($before + 1, $this->aiTaskKeys);
        self::assertTrue($this->em()->getConnection()->fetchOne('SELECT dispatch_pending FROM cash_payment_intake_draft WHERE id = ?', [$firstId]));
    }

    public function testLiveLeaseRetriesUnprocessedStepInsteadOfAcknowledgingIt(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $transport = $client->getContainer()->get('messenger.transport.async_payment_intake');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $poll = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(\App\Telegram\Message\RefreshPaymentIntakeMessage::class, $poll);
        $this->em()->getConnection()->executeStatement("UPDATE telegram_payment_intake_session SET lease_until = CURRENT_TIMESTAMP + INTERVAL '10 seconds' WHERE company_id = ?", [self::COMPANY]);
        $handler = $client->getContainer()->get(\App\Telegram\MessageHandler\RefreshPaymentIntakeMessageHandler::class);
        $this->expectException(\Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException::class);
        $handler($poll);
    }

    public function testKnownIntakeSourceCannotReachLegacyWithOnlyActorlessBindingRemaining(): void
    {
        $client = $this->setupClient(true, true, false);
        $binding = $this->em()->getRepository(ClientBinding::class)->findOneBy(['company' => self::COMPANY]);
        self::assertInstanceOf(ClientBinding::class, $binding);
        $bot = $binding->getBot();
        $owner = $binding->getCompany()->getUser();
        self::assertInstanceOf(\App\Company\Entity\User::class, $owner);
        $cash = $client->getContainer()->get(\App\Cash\Facade\CashFacade::class);
        $cash->requestPaymentIntake(new \App\Cash\Application\DTO\PaymentIntake\RequestPaymentIntakeCommand(self::COMPANY, (string) $owner->getId(), (string) $binding->getMoneyAccount()?->getId(), (string) $bot->getId(), '70090', '55', 'Оплатил 1500', new \DateTimeImmutable('2026-11-13')));
        $secondId = '11111111-1111-1111-1111-abcd0000ee02';
        $company = CompanyBuilder::aCompany()->withId($secondId)->withOwner($owner)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->withId('33333333-3333-3333-3333-abcd0000ee02')->forCompany($company)->build();
        $legacy = new ClientBinding(Uuid::uuid7()->toString(), $company, $bot, $binding->getTelegramUser());
        $legacy->setMoneyAccount($account);
        $project = new \App\Company\Entity\ProjectDirection(Uuid::uuid7()->toString(), $company, 'Общий', \App\Company\Entity\ProjectDirection::CODE_GENERAL);
        $center = new \App\Company\Entity\FinancialResponsibilityCenter($secondId, \App\Company\Entity\FinancialResponsibilityCenter::CODE_GENERAL, \App\Company\Entity\FinancialResponsibilityCenter::NAME_GENERAL);
        foreach ([$company, $account, $legacy, $project, $center, new \App\Company\Entity\FinancialResponsibilityCenterProject($secondId, $project, $center)] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->remove($binding);
        $this->em()->flush();
        $this->post($client, ['message' => $this->message('+1500 доход')]);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = ?', [$secondId]));
        $result = ($client->getContainer()->get(\App\Telegram\Application\CreateTelegramCashTransactionAction::class))(new \App\Telegram\Application\DTO\CreateTelegramCashTransactionCommand((string) $bot->getId(), $secondId, (string) $account->getId(), 'RUB', '70090', '55', '70090', 7001, 1794574800, '+1500 доход'));
        self::assertNotNull($result);
        self::assertTrue($result->skippedPaymentIntakeSource);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = ?', [$secondId]));
    }

    public function testReplayedFieldReplyCannotAmendAnotherSelectedRowAfterInterveningEdit(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $session = $this->em()->getRepository(\App\Telegram\Entity\PaymentIntakeSession::class)->findOneBy(['companyId' => self::COMPANY]);
        self::assertInstanceOf(\App\Telegram\Entity\PaymentIntakeSession::class, $session);
        $items = [];
        for ($i = 0; $i < 2; ++$i) {
            $items[] = ['id' => Uuid::uuid7()->toString(), 'transaction_id' => Uuid::uuid7()->toString(), 'amount' => '100.00', 'currency' => 'RUB', 'date' => '2026-11-13', 'direction' => 'outflow', 'execution_status' => 'paid', 'paid_confirmed' => false, 'category_id' => null, 'project_id' => null, 'cfo_id' => null, 'description' => 'Расход '.$i, 'questions' => [], 'provenance' => []];
        }
        $session->markDelivered('1:needs_clarification', ['900']);
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement("UPDATE cash_payment_intake_draft SET status = 'needs_clarification', items = CAST(? AS JSONB) WHERE company_id = ?", [json_encode($items, \JSON_THROW_ON_ERROR), self::COMPANY]);
        $prefix = 'pi:'.\App\Telegram\Application\PaymentIntakePresenter::encodeId($session->getId()).':';
        $callback = ['id' => 'edit-replay', 'from' => ['id' => 70090], 'message' => ['chat' => ['id' => 70090]], 'data' => $prefix.'1:e'.\App\Telegram\Application\PaymentIntakePresenter::encodeId($items[0]['id'])];
        $reply = array_replace($this->message('сумма 300'), ['message_id' => 56, 'reply_to_message' => ['message_id' => 900]]);
        $this->post($client, ['callback_query' => $callback]);
        $this->post($client, ['message' => $reply]);
        $callback['data'] = $prefix.'2:e'.\App\Telegram\Application\PaymentIntakePresenter::encodeId($items[1]['id']);
        $this->post($client, ['callback_query' => $callback]);
        $this->post($client, ['message' => array_replace($reply, ['message_id' => 57, 'text' => 'сумма 400'])]);
        $callback['data'] = $prefix.'3:e'.\App\Telegram\Application\PaymentIntakePresenter::encodeId($items[1]['id']);
        $this->post($client, ['callback_query' => $callback]);
        $this->post($client, ['message' => $reply]);
        $draft = $client->getContainer()->get(\App\Cash\Facade\CashFacade::class)->getPaymentIntakeDraft(self::COMPANY, '22222222-2222-2222-2222-abcd0000ee01', $session->getDraftId());
        self::assertSame('300', $draft->items[0]['amount']);
        self::assertSame('400', $draft->items[1]['amount']);
        self::assertSame(3, $draft->revision);
    }

    public function testConcurrentAmendDuringFinalCardPreservesNewDeliveryRequest(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $transport = $client->getContainer()->get('messenger.transport.async_payment_intake');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $session = $this->em()->getRepository(\App\Telegram\Entity\PaymentIntakeSession::class)->findOneBy(['companyId' => self::COMPANY]);
        self::assertInstanceOf(\App\Telegram\Entity\PaymentIntakeSession::class, $session);
        $item = ['id' => Uuid::uuid7()->toString(), 'transaction_id' => Uuid::uuid7()->toString(), 'amount' => '100.00', 'currency' => 'RUB', 'date' => '2026-11-13', 'direction' => 'outflow', 'execution_status' => 'paid', 'paid_confirmed' => false, 'category_id' => null, 'project_id' => null, 'cfo_id' => null, 'description' => 'Расход', 'questions' => [], 'provenance' => []];
        $this->em()->getConnection()->executeStatement("UPDATE cash_payment_intake_draft SET status = 'needs_clarification', items = CAST(? AS JSONB) WHERE company_id = ?", [json_encode([$item], \JSON_THROW_ON_ERROR), self::COMPANY]);
        $handler = $client->getContainer()->get(\App\Telegram\MessageHandler\RefreshPaymentIntakeMessageHandler::class);
        for ($i = 0; $i < 2; ++$i) {
            $sent = $transport->getSent();
            $message = $sent[count($sent) - 1]->getMessage();
            self::assertInstanceOf(\App\Telegram\Message\RefreshPaymentIntakeMessage::class, $message);
            $handler($message);
        }
        $this->duringDelivery = function () use ($client, $session, $item): void {
            $client->getContainer()->get(\App\Cash\Facade\CashFacade::class)->amendPaymentIntakeDraft(new \App\Cash\Application\DTO\PaymentIntake\AmendPaymentIntakeDraftCommand(self::COMPANY, '22222222-2222-2222-2222-abcd0000ee01', $session->getDraftId(), 1, [$item['id'] => ['amount' => '250.00']]));
            $session->requireDelivery();
            $this->em()->flush();
        };
        $sent = $transport->getSent();
        $final = $sent[count($sent) - 1]->getMessage();
        self::assertInstanceOf(\App\Telegram\Message\RefreshPaymentIntakeMessage::class, $final);
        $handler($final);
        self::assertTrue($this->em()->getConnection()->fetchOne('SELECT delivery_pending FROM telegram_payment_intake_session WHERE company_id = ?', [self::COMPANY]));
        for ($i = 0; $i < 3; ++$i) {
            $sent = $transport->getSent();
            $next = $sent[count($sent) - 1]->getMessage();
            self::assertInstanceOf(\App\Telegram\Message\RefreshPaymentIntakeMessage::class, $next);
            $handler($next);
        }
        self::assertStringContainsString('250.00', implode("\n", array_column($this->sent, 'text')));
        self::assertSame('2:needs_clarification', $this->em()->getConnection()->fetchOne('SELECT delivered_state FROM telegram_payment_intake_session WHERE company_id = ?', [self::COMPANY]));
    }

    public function testFinanceReaderCanConfigureReportsButCannotCreatePayment(): void
    {
        $client = $this->setupClient(true, true);
        $binding = $this->em()->getRepository(ClientBinding::class)->findOneBy(['company' => self::COMPANY]);
        self::assertInstanceOf(ClientBinding::class, $binding);
        $reader = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('tg-reader@example.test')->build();
        $role = new \App\Company\Entity\CompanyRole(Uuid::uuid7()->toString(), 'Просмотр финансов', ['finance' => 'read'], $binding->getCompany());
        $member = new \App\Company\Entity\CompanyMember(Uuid::uuid7()->toString(), $binding->getCompany(), $reader, \App\Company\Entity\CompanyMember::ROLE_OPERATOR);
        $member->setAccessRole($role);
        $binding->setActorUserId((string) $reader->getId());
        foreach ([$reader, $role, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->post($client, ['message' => $this->message('/reports')]);
        self::assertStringContainsString('Настройка отчетов', implode("\n", array_column($this->sent, 'text')));
        $this->post($client, ['callback_query' => ['id' => 'report-reader', 'from' => ['id' => 70090], 'message' => ['message_id' => 900, 'chat' => ['id' => 70090]], 'data' => 'rep:toggle:1']]);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT metrics_mask FROM report_subscriptions WHERE company_id = ?', [self::COMPANY]));
        $this->post($client, ['message' => array_replace($this->message('+1500 доход'), ['message_id' => 57])]);
        self::assertStringContainsString('Нет права записи', $this->lastText());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = ?', [self::COMPANY]));
        self::assertCount(0, $this->aiTaskKeys);
    }

    public function testManualFieldReplyWorksWithoutAiGrantAndDoesNotRequestNewParse(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $session = $this->em()->getRepository(\App\Telegram\Entity\PaymentIntakeSession::class)->findOneBy(['companyId' => self::COMPANY]);
        self::assertInstanceOf(\App\Telegram\Entity\PaymentIntakeSession::class, $session);
        $item = ['id' => Uuid::uuid7()->toString(), 'transaction_id' => Uuid::uuid7()->toString(), 'amount' => '100.00', 'currency' => 'RUB', 'date' => '2026-11-13', 'direction' => 'outflow', 'execution_status' => 'paid', 'paid_confirmed' => false, 'category_id' => null, 'project_id' => null, 'cfo_id' => null, 'description' => 'Расход', 'questions' => [], 'provenance' => []];
        $session->markDelivered('1:needs_clarification', ['900']);
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement("UPDATE cash_payment_intake_draft SET status = 'needs_clarification', items = CAST(? AS JSONB) WHERE company_id = ?", [json_encode([$item], \JSON_THROW_ON_ERROR), self::COMPANY]);
        $this->aiGranted = false;
        $before = count($this->aiTaskKeys);
        $callback = ['id' => 'manual-offline', 'from' => ['id' => 70090], 'message' => ['chat' => ['id' => 70090]], 'data' => 'pi:'.\App\Telegram\Application\PaymentIntakePresenter::encodeId($session->getId()).':1:e'.\App\Telegram\Application\PaymentIntakePresenter::encodeId($item['id'])];
        $this->post($client, ['callback_query' => $callback]);
        $this->post($client, ['message' => array_replace($this->message('сумма 250'), ['message_id' => 56, 'reply_to_message' => ['message_id' => 900]])]);
        $draft = $client->getContainer()->get(\App\Cash\Facade\CashFacade::class)->getPaymentIntakeDraft(self::COMPANY, '22222222-2222-2222-2222-abcd0000ee01', $session->getDraftId());
        self::assertSame('250', $draft->items[0]['amount']);
        self::assertSame(2, $draft->revision);
        self::assertCount($before, $this->aiTaskKeys);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_payment_intake_draft WHERE company_id = ?', [self::COMPANY]));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = ?', [self::COMPANY]));
    }

    public function testSourceRecoveryContinuesPastWrongActorBindingBeforeOwnedBinding(): void
    {
        $client = $this->setupClient(true, true);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        $original = $this->em()->getRepository(ClientBinding::class)->findOneBy(['company' => self::COMPANY]);
        self::assertInstanceOf(ClientBinding::class, $original);
        $company = $original->getCompany();
        $bot = $original->getBot();
        $telegramUser = $original->getTelegramUser();
        $account = $original->getMoneyAccount();
        $actor = $original->getActorUserId();
        $this->em()->remove($original);
        $this->em()->flush();
        $this->em()->clear();
        $company = $this->em()->getReference(\App\Company\Entity\Company::class, self::COMPANY);
        $bot = $this->em()->getReference(TelegramBot::class, (string) $bot->getId());
        $telegramUser = $this->em()->getReference(TelegramUser::class, (string) $telegramUser->getId());
        $account = $this->em()->getReference(\App\Cash\Entity\Accounts\MoneyAccount::class, (string) $account?->getId());
        self::assertInstanceOf(\App\Company\Entity\Company::class, $company);
        self::assertInstanceOf(TelegramBot::class, $bot);
        self::assertInstanceOf(TelegramUser::class, $telegramUser);
        self::assertInstanceOf(\App\Cash\Entity\Accounts\MoneyAccount::class, $account);
        $other = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('tg-wrong-actor@example.test')->build();
        $otherCompany = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-abcd0000ee02')->withOwner($other)->build();
        $wrong = new ClientBinding(Uuid::uuid7()->toString(), $otherCompany, $bot, $telegramUser);
        $wrong->setActorUserId((string) $other->getId());
        $wrong->select(true);
        foreach ([$other, $otherCompany, $wrong] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $owned = new ClientBinding(Uuid::uuid7()->toString(), $company, $bot, $telegramUser);
        $owned->setActorUserId($actor);
        $owned->setMoneyAccount($account);
        $this->em()->persist($owned);
        $this->em()->flush();
        $candidates = $this->em()->getRepository(ClientBinding::class)->findBy(['telegramUser' => $telegramUser, 'bot' => $bot, 'status' => ClientBinding::STATUS_ACTIVE]);
        self::assertSame($wrong->getId(), $candidates[0]->getId(), 'Fixture must exercise wrong actor before the matching binding.');
        $before = count($this->aiTaskKeys);
        $this->post($client, ['message' => $this->message('Оплатил рекламу 1500')]);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM telegram_payment_intake_session WHERE company_id = ?', [self::COMPANY]));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM telegram_payment_intake_session WHERE company_id = ?', [(string) $otherCompany->getId()]));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_payment_intake_draft WHERE company_id = ?', [self::COMPANY]));
        self::assertCount($before, $this->aiTaskKeys);
    }

    private function seedSessionHistory(int $count, bool $candidate, int $start = 1): void
    {
        $pending = $candidate ? 'TRUE' : 'FALSE';
        $this->em()->getConnection()->executeStatement("INSERT INTO telegram_payment_intake_session (id,binding_id,bot_id,company_id,chat_id,message_id,draft_id,source_revision,delivered_state,delivery_pending,delivery_cursor,sending_state,reply_message_ids,editing_item_id,editing_revision,last_sequence,pending_poll,poll_generation,poll_until,lease_until) SELECT lpad(n::text,32,'0')::uuid,s.binding_id,s.bot_id,s.company_id,s.chat_id,CAST(9000+n AS VARCHAR),'00000000-0000-0000-0000-999999999999'::uuid,'original','',$pending,0,'','[]'::json,NULL,NULL,-1,NULL,NULL,NULL,NULL FROM telegram_payment_intake_session s CROSS JOIN generate_series(CAST(:start AS INT), CAST(:end AS INT)) n WHERE s.company_id = :company AND s.message_id = '55'", ['start' => $start, 'end' => $start + $count - 1, 'company' => self::COMPANY]);
    }

    private function lastText(): string
    {
        self::assertNotEmpty($this->sent);

        return (string) ($this->sent[count($this->sent) - 1]['text'] ?? '');
    }

    private function setupClient(bool $actorBound, bool $enabled = false, bool $intakeEnabled = true): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-abcd0000ee01')->withEmail('tg-intake@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY)->withOwner($owner)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->withId('33333333-3333-3333-3333-abcd0000ee01')->forCompany($company)->build();
        $bot = new TelegramBot(Uuid::uuid7()->toString(), 'TEST-TOKEN');
        $bot->setIsActive(true);
        $user = new TelegramUser(Uuid::uuid7()->toString(), '70090');
        $binding = new ClientBinding(Uuid::uuid7()->toString(), $company, $bot, $user);
        $binding->setMoneyAccount($account);
        if ($actorBound) {
            $binding->setActorUserId((string) $owner->getId());
        }
        $project = new \App\Company\Entity\ProjectDirection(Uuid::uuid7()->toString(), $company, 'Общий', \App\Company\Entity\ProjectDirection::CODE_GENERAL);
        $center = new \App\Company\Entity\FinancialResponsibilityCenter(self::COMPANY, \App\Company\Entity\FinancialResponsibilityCenter::CODE_GENERAL, \App\Company\Entity\FinancialResponsibilityCenter::NAME_GENERAL);
        $pair = new \App\Company\Entity\FinancialResponsibilityCenterProject(self::COMPANY, $project, $center);
        foreach ([$owner, $company, $account, $bot, $user, $binding, $project, $center, $pair] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $ai = $this->createStub(AiTaskFacadeInterface::class);
        $ai->method('budgetFor')->willReturn(new \App\ServiceGateway\Application\DTO\AiCompanyBudget(1000, 0, 10000, 0, 90));
        $ai->method('grantedCompanyIds')->willReturnCallback(fn (array $ids): array => $this->aiGranted ? $ids : []);
        $ai->method('isEnabledForCompany')->willReturnCallback(fn (): bool => $enabled && $this->aiGranted);
        $taskIdFor = static fn (string $companyId, string $type, string $key): string => Uuid::uuid5(Uuid::NAMESPACE_URL, $companyId.'/'.$type.'/'.$key)->toString();
        $ai->method('taskIdFor')->willReturnCallback($taskIdFor);
        $ai->method('requestTask')->willReturnCallback(function (string $companyId, string $type, array $payload, string $key) use ($taskIdFor): string {
            $this->aiTaskKeys[] = $key;

            return $taskIdFor($companyId, $type, $key);
        });
        $client->getContainer()->set(AiTaskFacadeInterface::class, $ai);
        if (!$intakeEnabled) {
            $container = $client->getContainer();
            $container->set(\App\Telegram\Application\PaymentIntakeTelegramAction::class, new \App\Telegram\Application\PaymentIntakeTelegramAction($this->em(), $container->get(\App\Cash\Facade\CashFacade::class), $container->get(\App\Company\Facade\CompanyFacade::class), $container->get(\App\Company\Facade\FinancialResponsibilityCenterFacade::class), $ai, $container->get(\App\Telegram\Application\QueuePaymentIntakePollAction::class), false));
        }
        $client->getContainer()->set('http_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if ($this->failNextDelivery) {
                $this->failNextDelivery = false;
                throw new \Symfony\Component\HttpClient\Exception\TransportException('Timeout at a bot token URL');
            }
            if (null !== $this->duringDelivery) {
                $callback = $this->duringDelivery;
                $this->duringDelivery = null;
                $callback();
            }
            if ($this->replaceLeaseOnDelivery) {
                $this->replaceLeaseOnDelivery = false;
                $this->em()->getConnection()->executeStatement("UPDATE telegram_payment_intake_session SET lease_until = CURRENT_TIMESTAMP + INTERVAL '120 seconds' WHERE company_id = ?", [self::COMPANY]);
            }
            $this->sent[] = json_decode((string) ($options['body'] ?? '{}'), true);

            return new MockResponse('{"ok":true,"result":{"message_id":900}}');
        }));

        return $client;
    }

    /** @return array<string, mixed> */
    private function message(string $text): array
    {
        return ['message_id' => 55, 'date' => 1794574800, 'chat' => ['id' => 70090, 'type' => 'private'], 'from' => ['id' => 70090], 'text' => $text];
    }

    /** @param array<string, mixed> $payload */
    private function post(KernelBrowser $client, array $payload): void
    {
        $client->request('POST', '/telegram/webhook', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'test-secret-123'], json_encode(['update_id' => 7001] + $payload, \JSON_THROW_ON_ERROR));
    }
}

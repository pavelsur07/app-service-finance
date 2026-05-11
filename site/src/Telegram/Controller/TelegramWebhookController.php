<?php

namespace App\Telegram\Controller;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\ImportJob;
use App\Telegram\Entity\ReportSubscription;
use App\Telegram\Entity\TelegramBot;
use App\Telegram\Application\CreateTelegramCashTransactionAction;
use App\Telegram\Application\DTO\CreateTelegramCashTransactionCommand;
use App\Telegram\Entity\TelegramUser;
use App\Telegram\Repository\BotLinkRepository;
use App\Telegram\Repository\TelegramBotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramWebhookController extends AbstractController
{
    public function __construct(
        private readonly TelegramBotRepository $botRepository,
        private readonly BotLinkRepository $botLinkRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly CreateTelegramCashTransactionAction $createTelegramCashTransactionAction,
    ) {
    }

    // Вебхук стал глобальным: один endpoint обслуживает все запросы, активного бота выбираем внутри
    #[Route('/telegram/webhook', name: 'telegram_webhook', methods: ['POST', 'GET'])]
    // Обработчик объявлен явным методом, чтобы следовать стилю контроллеров проекта
    public function webhook(Request $request): Response
    {
        $payload = $request->getContent();
        $update = json_decode($payload, true);

        if (!\is_array($update)) {
            return new JsonResponse(['status' => 'ok']);
        }

        try {
            if ($request->isMethod(Request::METHOD_GET)) {
                return new JsonResponse(['status' => 'ok']);
            }

            // Находим активного бота, чтобы принимать сообщения независимо от конкретного токена маршрута
            $bot = $this->botRepository->findActiveBot();
            if (!$bot || !$bot->isActive()) {
                return new JsonResponse(['status' => 'inactive_bot']);
            }

            // Фиксируем базовую информацию об апдейте для диагностики
            $message = is_array($update['message'] ?? null) ? $update['message'] : null;
            $callbackQuery = is_array($update['callback_query'] ?? null) ? $update['callback_query'] : null;
            $editedMessage = is_array($update['edited_message'] ?? null) ? $update['edited_message'] : null;
            $rawText = is_string($message['text'] ?? null) ? $message['text'] : null;
            $chatId = $this->extractChatId($message, $callbackQuery, $editedMessage);
            $this->logger->info('Telegram update получен', [
                'update_keys' => array_keys($update),
                'chat_id' => $chatId,
                'text' => $this->shortenText($rawText),
            ]);

            if ($callbackQuery) {
                return $this->handleCallbackQuery($bot, $callbackQuery);
            }

            if (!$message) {
                return new JsonResponse(['status' => 'ignored']);
            }

            $document = is_array($message['document'] ?? null) ? $message['document'] : null;
            $text = is_string($message['text'] ?? null) ? $message['text'] : null;

            // Сначала принимаем файлы, чтобы не игнорировать документы без текстового тела
            if ($document) {
                return $this->handleDocument($bot, $message, $document);
            }

            // Обрабатываем только текстовые сообщения
            if (!\is_string($text)) {
                return new JsonResponse(['status' => 'ignored']);
            }

            if (str_starts_with($text, '/start')) {
                return $this->handleStart($bot, $message, $text);
            }

            if (str_starts_with($text, '/set_cash')) {
                return $this->handleSetCash($bot, $message, $text);
            }

            if (str_starts_with($text, '/reports')) {
                return $this->handleReports($bot, $message);
            }

            return $this->handleTextMessage($bot, $update, $message, $text);
        } catch (\Throwable $e) {
            error_log('[TELEGRAM_WEBHOOK_EXCEPTION] '.$e->getMessage());
            error_log('[TELEGRAM_WEBHOOK_EXCEPTION] file='.$e->getFile().':'.$e->getLine());

            return new JsonResponse(['status' => 'ok']);
        }
    }

    private function handleStart(TelegramBot $bot, array $message, string $text): Response
    {
        $conn = $this->entityManager->getConnection();
        // Открываем транзакцию, потому что пессимистическая блокировка требует активной транзакции
        $conn->beginTransaction();

        try {
            // Нормализуем текст и достаем токен различными способами, чтобы поддержать варианты deep-link
            $normalizedText = trim($text);
            [$startToken, $parsePath] = $this->extractStartToken($normalizedText);

            if (null === $startToken || '' === $startToken) {
                // Нет токена: подсказываем пользователю выполнить привязку корректно
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }

                return $this->respondWithMessage($bot, $message, 'Сначала привяжите аккаунт через ссылку из кабинета компании');
            }

            // для диагностики проблем привязки
            $this->logger->info('Парсинг /start', [
                'raw_text' => $this->shortenText($normalizedText),
                'token' => $startToken,
                'parse_path' => $parsePath,
            ]);

            // Ищем BotLink с блокировкой для корректной отметки использования
            $botLink = $this->botLinkRepository->findOneByTokenForUpdate($startToken);
            if (!$botLink || $botLink->getBot()->getId() !== $bot->getId()) {
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }

                return $this->respondWithMessage($bot, $message, 'Ссылка не найдена. Сгенерируйте новую.');
            }

            // Бот глобальный, компанию для привязки берем из BotLink, а не из бота
            if ($botLink->getExpiresAt() < new \DateTimeImmutable()) {
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }

                return $this->respondWithMessage($bot, $message, 'Ссылка истекла. Сгенерируйте новую.');
            }

            if (null !== $botLink->getUsedAt()) {
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }

                return $this->respondWithMessage($bot, $message, 'Ссылка уже использована. Создайте новую.');
            }

            $from = $message['from'] ?? [];
            $telegramUser = $this->syncTelegramUser($from);
            if (!$telegramUser) {
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }

                return new JsonResponse(['status' => 'ignored']);
            }

            // Создаем привязку клиента, если ее еще нет
            $clientBinding = $this->entityManager->getRepository(ClientBinding::class)->findOneBy([
                'company' => $botLink->getCompany(),
                'bot' => $bot,
                'telegramUser' => $telegramUser,
            ]);

            if (!$clientBinding) {
                $clientBinding = new ClientBinding(
                    Uuid::uuid4()->toString(),
                    $botLink->getCompany(),
                    $bot,
                    $telegramUser,
                );
                $this->entityManager->persist($clientBinding);
            }

            // Помечаем ссылку использованной
            $botLink->markUsed();

            $this->entityManager->flush();

            $conn->commit();

            return $this->respondWithMessage(
                $bot,
                $message,
                'Привязка выполнена',
                $this->buildMainMenuReplyMarkup()
            );
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $e;
        }
    }

    private function handleSetCash(TelegramBot $bot, array $message, string $text): Response
    {
        // Фиксируем входящее сообщение для диагностики в docker-логах
        $chatId = $this->extractChatId($message, null, null);
        $normalizedText = trim($text);
        $parts = preg_split('/\s+/', $normalizedText);
        $requestedMoneyAccountId = isset($parts[1]) ? trim($parts[1]) : null;
        $requestedMoneyAccountId = '' === $requestedMoneyAccountId ? null : $requestedMoneyAccountId;

        $this->logger->info('Обработка /set_cash', [
            'chat_id' => $chatId,
            'raw_text' => $this->shortenText($normalizedText),
            'money_account_id' => $requestedMoneyAccountId,
        ]);

        // Синхронизируем пользователя, чтобы команда работала даже после переустановки бота
        $telegramUser = $this->syncTelegramUser($message['from'] ?? []);
        if (!$telegramUser) {
            return $this->respondWithMessageAndMenu($bot, $message, 'Сначала выполните привязку через ссылку /start');
        }

        $clientBindings = $this->entityManager->getRepository(ClientBinding::class)->findBy([
            'telegramUser' => $telegramUser,
            'status' => ClientBinding::STATUS_ACTIVE,
        ]);

        if (!$clientBindings) {
            return $this->respondWithMessageAndMenu(
                $bot,
                $message,
                'У вас нет активных привязок. Откройте ссылку привязки из кабинета компании.'
            );
        }

        // Если пришел аргумент, пробуем сразу привязать указанную кассу
        if ($requestedMoneyAccountId) {
            $moneyAccount = $this->entityManager->getRepository(MoneyAccount::class)->find($requestedMoneyAccountId);
            if (!$moneyAccount instanceof MoneyAccount) {
                return $this->respondWithMessageAndMenu($bot, $message, 'Касса не найдена или не принадлежит вашей компании.');
            }

            // Ищем привязку с той же компанией, чтобы не дать выбрать чужую кассу
            $clientBinding = null;
            foreach ($clientBindings as $binding) {
                if (!$binding instanceof ClientBinding) {
                    continue;
                }

                if ($binding->getCompany()->getId() === $moneyAccount->getCompany()->getId()) {
                    $clientBinding = $binding;
                    break;
                }
            }

            if (!$clientBinding) {
                return $this->respondWithMessageAndMenu($bot, $message, 'Касса не найдена или не принадлежит вашей компании.');
            }

            if (ClientBinding::STATUS_BLOCKED === $clientBinding->getStatus()) {
                return $this->respondWithMessageAndMenu($bot, $message, 'Доступ заблокирован администратором');
            }

            $clientBinding->setMoneyAccount($moneyAccount);
            $this->entityManager->flush();

            // Логируем удачную установку кассы для быстрой диагностики
            $this->logger->info('Касса установлена через /set_cash', [
                'chat_id' => $chatId,
                'company_id' => $clientBinding->getCompany()->getId(),
                'money_account_id' => $moneyAccount->getId(),
            ]);

            return $this->respondWithMessageAndMenu(
                $bot,
                $message,
                sprintf('Касса установлена: %s', $moneyAccount->getName()),
            );
        }

        if (1 === count($clientBindings)) {
            $clientBinding = $clientBindings[0];

            if (ClientBinding::STATUS_BLOCKED === $clientBinding->getStatus()) {
                return $this->respondWithMessageAndMenu($bot, $message, 'Доступ заблокирован администратором');
            }

            $response = $this->sendMoneyAccountSelection($bot, $message, $clientBinding);
            $this->sendMainMenu($bot, $message);

            return $response;
        }

        // При нескольких активных привязках просим выбрать компанию, чтобы не перепутать кассу для разных организаций
        $keyboard = [];
        foreach ($clientBindings as $binding) {
            if (!$binding instanceof ClientBinding) {
                continue;
            }

            $keyboard[] = [[
                'text' => $binding->getCompany()->getName(),
                'callback_data' => sprintf('bind:%s:set_cash', $binding->getId()),
            ]];
        }

        $response = $this->respondWithMessage($bot, $message, 'Выберите компанию', [
            'inline_keyboard' => $keyboard,
        ]);

        $this->sendMainMenu($bot, $message);

        return $response;
    }

    private function handleCallbackQuery(TelegramBot $bot, array $callbackQuery): Response
    {
        $callbackData = $callbackQuery['data'] ?? null;
        if (!\is_string($callbackData)) {
            return new JsonResponse(['status' => 'ok']);
        }

        if (str_starts_with($callbackData, 'bind:')) {
            return $this->handleBindingSelectionCallback($bot, $callbackQuery, $callbackData);
        }

        if (str_starts_with($callbackData, 'cash:')) {
            return $this->handleCashSelectionCallback($bot, $callbackQuery, $callbackData);
        }

        if (str_starts_with($callbackData, 'rep:')) {
            return $this->handleReportsCallback($bot, $callbackQuery, $callbackData);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    private function handleReports(TelegramBot $bot, array $message): Response
    {
        $from = $message['from'] ?? [];
        $telegramUser = $this->syncTelegramUser($from);
        if (!$telegramUser) {
            return new JsonResponse(['status' => 'ignored']);
        }

        // Ищем активные привязки, как в MVP-логике /set_cash
        $clientBindings = $this->entityManager->getRepository(ClientBinding::class)->findBy([
            'telegramUser' => $telegramUser,
            'status' => ClientBinding::STATUS_ACTIVE,
        ]);

        if (!$clientBindings) {
            return $this->respondWithMessageAndMenu($bot, $message, 'Сначала привяжите аккаунт через ссылку из кабинета компании');
        }

        if (count($clientBindings) > 1) {
            return $this->respondWithMessageAndMenu($bot, $message, 'У вас несколько компаний. Для операций сначала выберите компанию командой /set_cash');
        }

        $clientBinding = $clientBindings[0];
        if (!$clientBinding instanceof ClientBinding) {
            return new JsonResponse(['status' => 'ok']);
        }

        if (!$clientBinding) {
            return $this->respondWithMessageAndMenu($bot, $message, 'Не найдена привязка. Отправьте /start.');
        }

        if (ClientBinding::STATUS_BLOCKED === $clientBinding->getStatus()) {
            return $this->respondWithMessageAndMenu($bot, $message, 'Доступ заблокирован');
        }

        $subscription = $this->entityManager->getRepository(ReportSubscription::class)->findOneBy([
            'company' => $clientBinding->getCompany(),
            'telegramUser' => $telegramUser,
        ]);

        if (!$subscription) {
            // Создаем подписку с дефолтными настройками
            $subscription = new ReportSubscription(
                Uuid::uuid4()->toString(),
                $clientBinding->getCompany(),
                $telegramUser,
                ReportSubscription::PERIOD_DAILY,
                '09:00'
            );
            $subscription->setMetricsMask(0);
            $subscription->enable();
            $this->entityManager->persist($subscription);
        }

        $this->entityManager->flush();

        $response = $this->respondWithMessage(
            $bot,
            $message,
            $this->buildReportMessage($subscription),
            $this->buildReportKeyboard($subscription)
        );

        $this->sendMainMenu($bot, $message);

        return $response;
    }

    private function handleBindingSelectionCallback(TelegramBot $bot, array $callbackQuery, string $callbackData): Response
    {
        $from = $callbackQuery['from'] ?? [];
        $telegramUser = $this->findTelegramUserByCallback($from);
        if (!$telegramUser) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Сначала выполните привязку через ссылку /start');
        }

        $parts = explode(':', $callbackData);
        if (3 !== count($parts) || 'set_cash' !== $parts[2]) {
            return new JsonResponse(['status' => 'ok']);
        }

        [$prefix, $bindingId] = [$parts[0], $parts[1]];
        if ('bind' !== $prefix) {
            return new JsonResponse(['status' => 'ok']);
        }

        $clientBinding = $this->entityManager->getRepository(ClientBinding::class)->find($bindingId);
        if (!$clientBinding instanceof ClientBinding || $clientBinding->getTelegramUser()->getId() !== $telegramUser->getId()) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Привязка не найдена.');
        }

        if (ClientBinding::STATUS_BLOCKED === $clientBinding->getStatus()) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Доступ заблокирован администратором');
        }

        return $this->sendMoneyAccountSelection($bot, $callbackQuery, $clientBinding);
    }

    private function handleCashSelectionCallback(TelegramBot $bot, array $callbackQuery, string $callbackData): Response
    {
        $from = $callbackQuery['from'] ?? [];
        $telegramUser = $this->findTelegramUserByCallback($from);
        if (!$telegramUser) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Сначала выполните привязку через ссылку /start');
        }

        $parts = explode(':', $callbackData);
        if (3 !== count($parts)) {
            return new JsonResponse(['status' => 'ok']);
        }

        [$prefix, $bindingEnc, $moneyAccountEnc] = $parts;
        if ('cash' !== $prefix) {
            return new JsonResponse(['status' => 'ok']);
        }

        try {
            $bindingId = $this->decodeUuid($bindingEnc);
            $moneyAccountId = $this->decodeUuid($moneyAccountEnc);
        } catch (\InvalidArgumentException) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Ошибка выбора кассы. Повторите /set_cash');
        }

        $clientBinding = $this->entityManager->getRepository(ClientBinding::class)->find($bindingId);
        if (!$clientBinding instanceof ClientBinding || $clientBinding->getTelegramUser()->getId() !== $telegramUser->getId()) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Привязка не найдена.');
        }

        if (ClientBinding::STATUS_BLOCKED === $clientBinding->getStatus()) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Доступ заблокирован администратором');
        }

        $moneyAccount = $this->entityManager->getRepository(MoneyAccount::class)->find($moneyAccountId);
        if (!$moneyAccount instanceof MoneyAccount) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Касса не найдена.');
        }

        // Проверяем, что выбранная касса принадлежит той же компании, что и привязка, чтобы не записать чужие данные
        if ($moneyAccount->getCompany()->getId() !== $clientBinding->getCompany()->getId()) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Нельзя выбрать кассу другой компании.');
        }

        $clientBinding->setMoneyAccount($moneyAccount);
        $this->entityManager->flush();

        // Логируем выбор кассы через inline-кнопку для быстрой диагностики
        $this->logger->info('Касса установлена через callback set_cash', [
            'chat_id' => $this->extractChatId(null, $callbackQuery, null),
            'company_id' => $clientBinding->getCompany()->getId(),
            'money_account_id' => $moneyAccount->getId(),
        ]);

        return $this->respondWithMessage($bot, $callbackQuery, sprintf('Касса установлена: %s', $moneyAccount->getName()));
    }

    private function handleReportsCallback(TelegramBot $bot, array $callbackQuery, string $callbackData): Response
    {
        $from = $callbackQuery['from'] ?? [];
        $telegramUser = $this->syncTelegramUser($from);
        if (!$telegramUser) {
            return new JsonResponse(['status' => 'ignored']);
        }

        // Берем активную привязку, как в /set_cash; если несколько — просим выбрать компанию
        $clientBindings = $this->entityManager->getRepository(ClientBinding::class)->findBy([
            'telegramUser' => $telegramUser,
            'status' => ClientBinding::STATUS_ACTIVE,
        ]);

        if (!$clientBindings) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Сначала привяжите аккаунт через ссылку из кабинета компании');
        }

        if (count($clientBindings) > 1) {
            return $this->respondWithMessage($bot, $callbackQuery, 'У вас несколько компаний. Для операций сначала выберите компанию командой /set_cash');
        }

        $clientBinding = $clientBindings[0];
        if (!$clientBinding instanceof ClientBinding) {
            return new JsonResponse(['status' => 'ok']);
        }

        if (!$clientBinding) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Не найдена привязка. Отправьте /start.');
        }

        if (ClientBinding::STATUS_BLOCKED === $clientBinding->getStatus()) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Доступ заблокирован');
        }

        $subscription = $this->entityManager->getRepository(ReportSubscription::class)->findOneBy([
            'company' => $clientBinding->getCompany(),
            'telegramUser' => $telegramUser,
        ]);

        if (!$subscription) {
            // Создаем подписку на лету, чтобы не зависеть от предыдущего вызова /reports
            $subscription = new ReportSubscription(
                Uuid::uuid4()->toString(),
                $clientBinding->getCompany(),
                $telegramUser,
                ReportSubscription::PERIOD_DAILY,
                '09:00'
            );
            $subscription->setMetricsMask(0);
            $this->entityManager->persist($subscription);
        }

        if (str_starts_with($callbackData, 'rep:toggle:')) {
            $bit = (int) substr($callbackData, strlen('rep:toggle:'));
            $allowedBits = [1, 2, 4];
            if (!in_array($bit, $allowedBits, true)) {
                return $this->respondWithMessage($bot, $callbackQuery, 'Неизвестная метрика.');
            }

            // Тоглим соответствующий бит
            $subscription->setMetricsMask($subscription->getMetricsMask() ^ $bit);
        } elseif (str_starts_with($callbackData, 'rep:period:')) {
            $periodicity = substr($callbackData, strlen('rep:period:'));
            $allowedPeriods = [
                ReportSubscription::PERIOD_DAILY,
                ReportSubscription::PERIOD_WEEKLY,
                ReportSubscription::PERIOD_MONTHLY,
            ];

            if (!in_array($periodicity, $allowedPeriods, true)) {
                return $this->respondWithMessage($bot, $callbackQuery, 'Некорректная периодичность.');
            }

            $subscription->setPeriodicity($periodicity);
        } elseif ('rep:on' === $callbackData) {
            $subscription->enable();
        } elseif ('rep:off' === $callbackData) {
            $subscription->disable();
        } else {
            return new JsonResponse(['status' => 'ok']);
        }

        $this->entityManager->flush();

        $text = $this->buildReportMessage($subscription);
        $replyMarkup = $this->buildReportKeyboard($subscription);

        // Обновляем существующее сообщение, если это возможно
        if (isset($callbackQuery['message']['message_id'], $callbackQuery['message']['chat']['id'])) {
            $chatId = (int) $callbackQuery['message']['chat']['id'];
            $messageId = (int) $callbackQuery['message']['message_id'];

            try {
                $response = $this->httpClient->request(
                    'POST',
                    sprintf('https://api.telegram.org/bot%s/editMessageText', $bot->getToken()),
                    [
                        'json' => [
                            'chat_id' => $chatId,
                            'message_id' => $messageId,
                            'text' => $text,
                            'reply_markup' => $replyMarkup,
                        ],
                    ]
                );

                // Принудительно читаем ответ, чтобы ленивый HttpClient реально отправил запрос
                $statusCode = $response->getStatusCode();
                $content = $response->getContent(false);

                if (200 !== $statusCode) {
                    error_log(sprintf('Telegram editMessageText HTTP error: %d, chat=%s', $statusCode, $chatId));
                } else {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded) && isset($decoded['ok']) && false === $decoded['ok']) {
                        $description = $decoded['description'] ?? 'unknown error';
                        error_log(sprintf('Telegram editMessageText API error: %s, chat=%s', $description, $chatId));
                    }
                }
            } catch (\Throwable $exception) {
                error_log(sprintf('Telegram editMessageText exception: %s', $exception->getMessage()));
            }

            return $this->respondWithMessage($bot, $callbackQuery, 'Настройки обновлены');
        }

        return $this->respondWithMessage($bot, $callbackQuery, $text, $replyMarkup);
    }

    private function handleDocument(TelegramBot $bot, array $message, array $document): Response
    {
        // Фиксируем пользователя, чтобы знать источник загрузки и иметь доступ к привязкам
        $telegramUser = $this->syncTelegramUser($message['from'] ?? []);
        if (!$telegramUser) {
            return new JsonResponse(['status' => 'ignored']);
        }

        // Ищем активные привязки пользователя
        $clientBindings = $this->entityManager->getRepository(ClientBinding::class)->findBy([
            'telegramUser' => $telegramUser,
            'status' => ClientBinding::STATUS_ACTIVE,
        ]);

        if (!$clientBindings) {
            return $this->respondWithMessage($bot, $message, 'Сначала привяжите аккаунт через ссылку из кабинета');
        }

        if (count($clientBindings) > 1) {
            return $this->respondWithMessage($bot, $message, 'У вас несколько компаний. Для импорта выберите компанию через /set_cash');
        }

        $clientBinding = $clientBindings[0];
        if (!$clientBinding instanceof ClientBinding) {
            return new JsonResponse(['status' => 'ok']);
        }

        if (ClientBinding::STATUS_BLOCKED === $clientBinding->getStatus()) {
            return $this->respondWithMessage($bot, $message, 'Доступ заблокирован администратором');
        }

        $fileId = is_string($document['file_id'] ?? null) ? (string) $document['file_id'] : null;
        if (!$fileId) {
            return new JsonResponse(['status' => 'ignored']);
        }

        $originalFilename = is_string($document['file_name'] ?? null) ? $document['file_name'] : 'import';

        try {
            // Запрашиваем путь к файлу через Telegram API
            $fileInfoResponse = $this->httpClient->request(
                'GET',
                sprintf('https://api.telegram.org/bot%s/getFile', $bot->getToken()),
                [
                    'query' => [
                        'file_id' => $fileId,
                    ],
                ],
            );
            $fileInfo = $fileInfoResponse->toArray(false);
        } catch (\Throwable) {
            return $this->respondWithMessage($bot, $message, 'Не удалось запросить файл, попробуйте позже.');
        }

        $filePath = is_array($fileInfo['result'] ?? null) && is_string($fileInfo['result']['file_path'] ?? null)
            ? $fileInfo['result']['file_path']
            : null;

        if (!$filePath) {
            return $this->respondWithMessage($bot, $message, 'Не удалось получить файл, попробуйте позже.');
        }

        try {
            // Скачиваем содержимое файла
            $fileResponse = $this->httpClient->request(
                'GET',
                sprintf('https://api.telegram.org/file/bot%s/%s', $bot->getToken(), $filePath),
            );
            $fileContent = $fileResponse->getContent();
        } catch (\Throwable) {
            return $this->respondWithMessage($bot, $message, 'Не удалось скачать файл, попробуйте позже.');
        }

        // Вычисляем хэш и готовим имя файла с сохранением расширения для наглядности
        $fileHash = hash('sha256', $fileContent);
        $extension = pathinfo($originalFilename, \PATHINFO_EXTENSION);
        $normalizedExtension = '' !== $extension ? '.'.strtolower($extension) : '';

        $storageDir = sprintf('%s/var/storage/telegram-imports', $this->getParameter('kernel.project_dir'));
        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            return $this->respondWithMessage($bot, $message, 'Не удалось подготовить директорию для файлов импорта.');
        }

        $targetPath = sprintf('%s/%s%s', $storageDir, $fileHash, $normalizedExtension);
        if (false === file_put_contents($targetPath, $fileContent)) {
            return $this->respondWithMessage($bot, $message, 'Не удалось сохранить файл на диск.');
        }

        // Создаем задачу импорта, чтобы не смешивать загрузку из Telegram с существующей системой импорта
        $importJob = new ImportJob(
            Uuid::uuid4()->toString(),
            $clientBinding->getCompany(),
            'telegram',
            $originalFilename,
            $fileHash,
            $telegramUser,
        );

        $this->entityManager->persist($importJob);
        $this->entityManager->flush();

        // В проекте пока нет очереди/команды для автоматического импорта выписки, поэтому ограничиваемся постановкой задачи
        $messageText = sprintf(
            'Файл получен. Импорт будет добавлен позже. Задача №%s, статус: %s',
            $importJob->getId(),
            $importJob->getStatus(),
        );

        return $this->respondWithMessage($bot, $message, $messageText);
    }

    private function handleTextMessage(TelegramBot $bot, array $update, array $message, string $text): Response
    {
        $normalizedText = trim($text);

        if ('📊 Отчёты' === $normalizedText) {
            return $this->handleReports($bot, $message);
        }

        if ('💼 Выбрать кассу' === $normalizedText) {
            return $this->handleSetCash($bot, $message, '/set_cash');
        }

        if ('➕ Добавить операцию' === $normalizedText) {
            return $this->respondWithMessage(
                $bot,
                $message,
                "Введите операцию сообщением:\n\n+120000 оплата от клиента\n-3500 реклама\n\n(Сначала выберите кассу через /set_cash)",
                $this->buildMainMenuReplyMarkup()
            );
        }

        if ('📥 Загрузить выписку' === $normalizedText) {
            return $this->respondWithMessage(
                $bot,
                $message,
                'Отправьте файл выписки (PDF/XLS). Мы сохраним его для импорта.',
                $this->buildMainMenuReplyMarkup()
            );
        }

        if ('⚙️ Помощь' === $normalizedText) {
            return $this->respondWithMessage(
                $bot,
                $message,
                "Команды:\n/set_cash — выбрать кассу\n/reports — отчёты\n\nОперации можно вводить текстом:\n+10000 доход\n-500 расход",
                $this->buildMainMenuReplyMarkup()
            );
        }

        $tgUserId = isset($message['from']['id']) ? (string) $message['from']['id'] : null;
        if (!$tgUserId) {
            return new JsonResponse(['status' => 'ok']);
        }

        $telegramUser = $this->entityManager->getRepository(TelegramUser::class)
            ->findOneBy(['tgUserId' => $tgUserId]);

        if (!$telegramUser) {
            return $this->respondWithMessage($bot, $message, 'Сначала привяжите аккаунт через ссылку из кабинета компании');
        }

        $clientBindings = $this->entityManager->getRepository(ClientBinding::class)->findBy([
            'telegramUser' => $telegramUser,
            'status' => ClientBinding::STATUS_ACTIVE,
        ]);

        if (!$clientBindings) {
            return $this->respondWithMessage($bot, $message, 'Сначала привяжите аккаунт через ссылку из кабинета компании');
        }

        if (count($clientBindings) > 1) {
            return $this->respondWithMessage($bot, $message, 'У вас несколько компаний. Для операций сначала выберите компанию командой /set_cash');
        }

        $clientBinding = $clientBindings[0];
        if (!$clientBinding instanceof ClientBinding) {
            return new JsonResponse(['status' => 'ok']);
        }

        if (ClientBinding::STATUS_BLOCKED === $clientBinding->getStatus()) {
            return $this->respondWithMessage($bot, $message, 'Доступ заблокирован администратором');
        }

        $moneyAccount = $clientBinding->getMoneyAccount();
        if (!$moneyAccount instanceof MoneyAccount) {
            return $this->respondWithMessage($bot, $message, 'Сначала выберите кассу: /set_cash');
        }

        $result = ($this->createTelegramCashTransactionAction)(new CreateTelegramCashTransactionCommand(
            botId: $bot->getId(),
            companyId: $clientBinding->getCompany()->getId(),
            moneyAccountId: $moneyAccount->getId(),
            currency: $moneyAccount->getCurrency(),
            chatId: isset($message['chat']['id']) ? (string) $message['chat']['id'] : null,
            messageId: isset($message['message_id']) ? (string) $message['message_id'] : null,
            fromId: isset($message['from']['id']) ? (string) $message['from']['id'] : null,
            updateId: isset($update['update_id']) ? (int) $update['update_id'] : null,
            messageDate: isset($message['date']) ? (int) $message['date'] : null,
            text: $text,
        ));

        if (null === $result) {
            return $this->respondWithMessage($bot, $message, 'Формат: потратил 2500 на рекламу');
        }


        if ($result->skippedMissingMessageIdentity) {
            $this->logger->warning('Telegram cash transaction skipped: message identity is incomplete.', [
                'update_id' => $update['update_id'] ?? null,
                'chat_id' => $message['chat']['id'] ?? null,
                'message_id' => $message['message_id'] ?? null,
            ]);

            return new JsonResponse(['status' => 'ok']);
        }

        if ($result->duplicate) {
            $this->logger->info('Telegram duplicate cash transaction skipped.', [
                'company_id' => $clientBinding->getCompany()->getId(),
                'money_account_id' => $moneyAccount->getId(),
            ]);

            return new JsonResponse(['status' => 'ok']);
        }

        $formattedAmount = $this->formatAmountForMessage($result->amount, $moneyAccount->getCurrency());

        return $this->respondWithMessage(
            $bot,
            $message,
            sprintf('Записал %s %s', $result->directionLabel, $formattedAmount)
        );
    }

    private function formatAmountForMessage(string $amount, string $currency): string
    {
        [$integerPart, $fractionalPart] = array_pad(explode('.', $amount, 2), 2, '00');
        $fractionalPart = rtrim($fractionalPart, '0');
        $fractionalPart = '' === $fractionalPart ? '' : ','.str_pad($fractionalPart, 2, '0');

        $formattedInteger = number_format((int) $integerPart, 0, '', ' ');

        $symbol = 'RUB' === $currency ? '₽' : $currency;

        return sprintf('%s%s %s', $formattedInteger, $fractionalPart, $symbol);
    }

    private function syncTelegramUser(array $from): ?TelegramUser
    {
        $tgUserId = isset($from['id']) ? (string) $from['id'] : null;
        if (!$tgUserId) {
            return null;
        }

        $telegramUser = $this->entityManager->getRepository(TelegramUser::class)
            ->findOneBy(['tgUserId' => $tgUserId]);

        if (!$telegramUser) {
            $telegramUser = new TelegramUser(Uuid::uuid4()->toString(), $tgUserId);
            $this->entityManager->persist($telegramUser);
        }

        // Обновляем пользовательские данные и дату активности
        $telegramUser->setUsername($from['username'] ?? null);
        $telegramUser->setFirstName($from['first_name'] ?? null);
        $telegramUser->setLastName($from['last_name'] ?? null);
        $telegramUser->touch();

        return $telegramUser;
    }

    private function buildReportMessage(ReportSubscription $subscription): string
    {
        $metricsMask = $subscription->getMetricsMask();
        $metrics = [
            1 => 'Баланс',
            2 => 'ДДС (доход/расход)',
            4 => 'Топ расходов',
        ];

        $metricLines = [];
        foreach ($metrics as $bit => $label) {
            $metricLines[] = sprintf('- %s: %s', $label, ($metricsMask & $bit) === $bit ? 'включено' : 'выключено');
        }

        $periodicityTitles = [
            ReportSubscription::PERIOD_DAILY => 'Каждый день',
            ReportSubscription::PERIOD_WEEKLY => 'Раз в неделю',
            ReportSubscription::PERIOD_MONTHLY => 'Раз в месяц',
        ];

        return implode("\n", array_merge(
            ['Настройка отчетов:'],
            $metricLines,
            [
                sprintf('Периодичность: %s', $periodicityTitles[$subscription->getPeriodicity()] ?? $subscription->getPeriodicity()),
                sprintf('Статус: %s', $subscription->isEnabled() ? 'включена' : 'выключена'),
            ]
        ));
    }

    private function buildReportKeyboard(ReportSubscription $subscription): array
    {
        $metricsMask = $subscription->getMetricsMask();

        $buildToggle = static fn (string $label, int $bit): array => [
            'text' => sprintf('%s %s', ($metricsMask & $bit) === $bit ? '✅' : '⚪️', $label),
            'callback_data' => sprintf('rep:toggle:%d', $bit),
        ];

        $buildPeriodicity = static function (string $label, string $value) use ($subscription): array {
            $isActive = $subscription->getPeriodicity() === $value;

            return [
                'text' => sprintf('%s %s', $isActive ? '✅' : '⚪️', $label),
                'callback_data' => sprintf('rep:period:%s', $value),
            ];
        };

        $statusButton = [
            'text' => $subscription->isEnabled() ? 'Выключить подписку' : 'Включить подписку',
            'callback_data' => $subscription->isEnabled() ? 'rep:off' : 'rep:on',
        ];

        return [
            'inline_keyboard' => [
                [
                    $buildToggle('Баланс', 1),
                    $buildToggle('ДДС (доход/расход)', 2),
                ],
                [
                    $buildToggle('Топ расходов', 4),
                ],
                [
                    $buildPeriodicity('День', ReportSubscription::PERIOD_DAILY),
                    $buildPeriodicity('Неделя', ReportSubscription::PERIOD_WEEKLY),
                    $buildPeriodicity('Месяц', ReportSubscription::PERIOD_MONTHLY),
                ],
                [$statusButton],
            ],
        ];
    }

    private function extractStartToken(string $text): array
    {
        // Возвращаем [token|null, источник], чтобы в логах понимать, какой путь парсинга сработал
        $token = null;
        $path = null;

        if (str_starts_with($text, '/start')) {
            // Поддерживаем варианты: "/start <token>", "/start<token>", "/start start=<token>"
            $afterStart = trim(mb_substr($text, mb_strlen('/start')));

            if ('' !== $afterStart) {
                if (str_starts_with($afterStart, 'start=')) {
                    $token = trim(mb_substr($afterStart, mb_strlen('start=')));
                    $path = '/start start=';
                } elseif (str_starts_with($afterStart, 'start-')) {
                    $token = trim(mb_substr($afterStart, mb_strlen('start-')));
                    $path = '/start start-';
                } else {
                    // Подчищаем возможные разделители вида "=token" или "-token"
                    $token = trim(ltrim($afterStart, "= -\t"));
                    $path = '/start';
                }
            }
        }

        if (null === $token && preg_match('/^start[-=]([A-Za-z0-9_-]{20,})$/', $text, $matches)) {
            $token = $matches[1];
            $path = 'start-prefix';
        }

        if (null === $token && !str_starts_with($text, '/start') && preg_match('/^[A-Za-z0-9_-]{20,}$/', $text)) {
            // Пользователь отправил один токен без команды
            $token = $text;
            $path = 'token-only';
        }

        return [$token, $path];
    }

    private function shortenText(?string $text): ?string
    {
        // Безопасно обрезаем текст для логов, чтобы не заливать длинные payload в stderr
        if (null === $text) {
            return null;
        }

        $trimmed = trim($text);

        return mb_strlen($trimmed) > 200 ? mb_substr($trimmed, 0, 200).'…' : $trimmed;
    }

    private function extractChatId(?array $message, ?array $callbackQuery, ?array $editedMessage): ?int
    {
        // Пробуем достать chat_id из разных типов апдейтов
        if (isset($message['chat']['id'])) {
            return (int) $message['chat']['id'];
        }

        if (isset($callbackQuery['message']['chat']['id'])) {
            return (int) $callbackQuery['message']['chat']['id'];
        }

        if (isset($editedMessage['chat']['id'])) {
            return (int) $editedMessage['chat']['id'];
        }

        return null;
    }

    private function respondWithMessage(TelegramBot $bot, array $message, string $text, ?array $replyMarkup = null): Response
    {
        // Отправляем простой ответ через Telegram API
        // chat.id лучше from.id: ответы должны приходить в чат (в том числе групповой), а не личные сообщения отправителя
        $chatId = null;

        // Обычное сообщение
        if (isset($message['chat']['id'])) {
            $chatId = (int) $message['chat']['id'];
        }

        // callback_query
        if (null === $chatId && isset($message['message']['chat']['id'])) {
            $chatId = (int) $message['message']['chat']['id'];
        }

        if (null === $chatId) {
            error_log('[TELEGRAM] chat_id not found, skip sendMessage');

            return new JsonResponse(['status' => 'ok']);
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                sprintf('https://api.telegram.org/bot%s/sendMessage', $bot->getToken()),
                [
                    'json' => array_filter(
                        [
                            'chat_id' => $chatId,
                            'text' => $text,
                            'reply_markup' => null !== $replyMarkup
                                ? json_encode($replyMarkup, \JSON_UNESCAPED_UNICODE)
                                : null,
                        ],
                        static fn ($value) => null !== $value,
                    ),
                ],
            );

            // HttpClient ленивый: дергаем статус/тело, чтобы запрос точно ушел
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if (200 !== $statusCode) {
                error_log(sprintf('Telegram sendMessage HTTP error: %d, chat=%s, text="%s"', $statusCode, $chatId, $text));
            } else {
                $decoded = json_decode($content, true);
                if (is_array($decoded) && isset($decoded['ok']) && false === $decoded['ok']) {
                    $description = $decoded['description'] ?? 'unknown error';
                    error_log(sprintf('Telegram sendMessage API error: %s, chat=%s, text="%s"', $description, $chatId, $text));
                }
            }
        } catch (\Throwable $exception) {
            error_log(sprintf('Telegram sendMessage exception: %s', $exception->getMessage()));
        }

        return new JsonResponse(['status' => 'ok']);
    }

    private function sendMoneyAccountSelection(TelegramBot $bot, array $message, ClientBinding $clientBinding): Response
    {
        if (ClientBinding::STATUS_BLOCKED === $clientBinding->getStatus()) {
            return $this->respondWithMessage($bot, $message, 'Доступ заблокирован администратором');
        }

        $moneyAccounts = $this->entityManager->getRepository(MoneyAccount::class)->findBy(
            ['company' => $clientBinding->getCompany()],
            ['sortOrder' => 'ASC', 'name' => 'ASC'],
        );

        if (!$moneyAccounts) {
            return $this->respondWithMessage(
                $bot,
                $message,
                'У компании нет касс. Создайте кассу в кабинете и повторите /set_cash.'
            );
        }

        $lines = ['Доступные кассы:'];
        $keyboard = [];
        $selectedMoneyAccountId = $clientBinding->getMoneyAccount()?->getId();

        foreach ($moneyAccounts as $account) {
            if (!$account instanceof MoneyAccount) {
                continue;
            }

            $shortId = mb_substr((string) $account->getId(), -6);
            $isSelected = null !== $selectedMoneyAccountId && $selectedMoneyAccountId === $account->getId();
            $lines[] = sprintf('- %s (%s)%s', $account->getName(), $shortId, $isSelected ? ' — выбрана' : '');
            $keyboard[] = [
                [
                    'text' => $account->getName(),
                    'callback_data' => sprintf(
                        'cash:%s:%s',
                        $this->encodeUuid($clientBinding->getId()),
                        $this->encodeUuid($account->getId()),
                    ),
                ],
            ];
        }

        $lines[] = '';
        $lines[] = 'Выберите кассу командой: /set_cash <ID>';

        // Логируем текущий список касс, чтобы понимать, что увидит пользователь
        $this->logger->info('Показываем список касс для /set_cash', [
            'chat_id' => $this->extractChatId($message, null, null),
            'company_id' => $clientBinding->getCompany()->getId(),
        ]);

        return $this->respondWithMessage($bot, $message, implode("\n", $lines), [
            'inline_keyboard' => $keyboard,
        ]);
    }

    private function buildMainMenuReplyMarkup(): array
    {
        return [
            'keyboard' => [
                ['📊 Отчёты', '💼 Выбрать кассу'],
                ['➕ Добавить операцию', '📥 Загрузить выписку'],
                ['⚙️ Помощь'],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
            'selective' => false,
        ];
    }

    private function sendMainMenu(TelegramBot $bot, array $message): void
    {
        $this->respondWithMessage($bot, $message, 'Главное меню', $this->buildMainMenuReplyMarkup());
    }

    private function respondWithMessageAndMenu(
        TelegramBot $bot,
        array $message,
        string $text,
        ?array $replyMarkup = null,
    ): Response {
        $response = $this->respondWithMessage($bot, $message, $text, $replyMarkup);

        $this->sendMainMenu($bot, $message);

        return $response;
    }

    private function encodeUuid(string $uuid): string
    {
        $bytes = Uuid::fromString($uuid)->getBytes();

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function decodeUuid(string $encoded): string
    {
        $base64 = strtr($encoded, '-_', '+/');
        $padding = strlen($base64) % 4;
        if (0 !== $padding) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $bytes = base64_decode($base64, true);
        if (false === $bytes) {
            throw new \InvalidArgumentException('Invalid encoded UUID');
        }

        try {
            return Uuid::fromBytes($bytes)->toString();
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Invalid encoded UUID', 0, $e);
        }
    }

    private function findTelegramUserByCallback(array $from): ?TelegramUser
    {
        $tgUserId = isset($from['id']) ? (string) $from['id'] : null;
        if (!$tgUserId) {
            return null;
        }

        return $this->entityManager->getRepository(TelegramUser::class)
            ->findOneBy(['tgUserId' => $tgUserId]);
    }
}

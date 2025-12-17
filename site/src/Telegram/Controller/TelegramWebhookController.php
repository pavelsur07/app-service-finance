<?php

namespace App\Telegram\Controller;

use App\Entity\CashTransaction;
use App\Enum\CashDirection;
use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\ReportSubscription;
use App\Telegram\Entity\ImportJob;
use App\Telegram\Entity\TelegramBot;
use App\Telegram\Entity\TelegramUser;
use App\Telegram\Repository\BotLinkRepository;
use App\Telegram\Repository\TelegramBotRepository;
use App\Entity\MoneyAccount;
use Doctrine\ORM\EntityManagerInterface;
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
    ) {
    }

    // Вебхук стал глобальным: один endpoint обслуживает все запросы, активного бота выбираем внутри
    #[Route('/telegram/webhook', name: 'telegram_webhook', methods: ['POST', 'GET'])]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod(Request::METHOD_GET)) {
            return new JsonResponse(['status' => 'ok']);
        }

        // Находим активного бота, чтобы принимать сообщения независимо от конкретного токена маршрута
        $bot = $this->botRepository->findActiveBot();
        if (!$bot || !$bot->isActive()) {
            return new JsonResponse(['status' => 'inactive_bot']);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['status' => 'ignored']);
        }

        $callbackQuery = $payload['callback_query'] ?? null;
        if (is_array($callbackQuery)) {
            return $this->handleCallbackQuery($bot, $callbackQuery);
        }

        $message = $payload['message'] ?? null;
        $document = is_array($message['document'] ?? null) ? $message['document'] : null;
        $text = $message['text'] ?? null;

        // Сначала принимаем файлы, чтобы не игнорировать документы без текстового тела
        if ($document) {
            return $this->handleDocument($bot, $message, $document);
        }

        // Обрабатываем только текстовые сообщения
        if (!\is_string($text)) {
            return new JsonResponse(['status' => 'ok']);
        }

        if (str_starts_with($text, '/start')) {
            return $this->handleStart($bot, $message, $text);
        }

        if (str_starts_with($text, '/set_cash')) {
            return $this->handleSetCash($bot, $message);
        }

        if (str_starts_with($text, '/reports')) {
            return $this->handleReports($bot, $message);
        }

        return $this->handleTextMessage($bot, $message, $text);
    }

    private function handleStart(TelegramBot $bot, array $message, string $text): Response
    {
        // Извлекаем токен после /start
        $startToken = trim(mb_substr($text, mb_strlen('/start')));
        if ($startToken === '') {
            return $this->respondWithMessage($bot, $message, 'Некорректная ссылка. Попробуйте сгенерировать новую.');
        }

        // Ищем BotLink с блокировкой для корректной отметки использования
        $botLink = $this->botLinkRepository->findOneByTokenForUpdate($startToken);
        if (!$botLink || $botLink->getBot()->getId() !== $bot->getId()) {
            return $this->respondWithMessage($bot, $message, 'Ссылка не найдена. Сгенерируйте новую.');
        }

        // Бот глобальный, компанию для привязки берем из BotLink, а не из бота
        if ($botLink->getExpiresAt() < new \DateTimeImmutable()) {
            return $this->respondWithMessage($bot, $message, 'Ссылка истекла. Сгенерируйте новую.');
        }

        if ($botLink->getUsedAt() !== null) {
            return $this->respondWithMessage($bot, $message, 'Ссылка уже использована. Создайте новую.');
        }

        $from = $message['from'] ?? [];
        $telegramUser = $this->syncTelegramUser($from);
        if (!$telegramUser) {
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

        return $this->respondWithMessage(
            $bot,
            $message,
            'Привязка выполнена. Настройте кассу командой /set_cash.'
        );
    }

    private function handleSetCash(TelegramBot $bot, array $message): Response
    {
        $tgUserId = isset($message['from']['id']) ? (string) $message['from']['id'] : null;
        if (!$tgUserId) {
            return new JsonResponse(['status' => 'ignored']);
        }

        $telegramUser = $this->entityManager->getRepository(TelegramUser::class)
            ->findOneBy(['tgUserId' => $tgUserId]);

        if (!$telegramUser) {
            return $this->respondWithMessage($bot, $message, 'Сначала выполните привязку через ссылку /start');
        }

        $clientBindings = $this->entityManager->getRepository(ClientBinding::class)->findBy([
            'telegramUser' => $telegramUser,
            'status' => ClientBinding::STATUS_ACTIVE,
        ]);

        if (!$clientBindings) {
            return $this->respondWithMessage(
                $bot,
                $message,
                'У вас нет активных привязок. Откройте ссылку привязки из кабинета компании.'
            );
        }

        if (count($clientBindings) === 1) {
            $clientBinding = $clientBindings[0];

            if ($clientBinding->getStatus() === ClientBinding::STATUS_BLOCKED) {
                return $this->respondWithMessage($bot, $message, 'Доступ заблокирован администратором');
            }

            return $this->sendMoneyAccountSelection($bot, $message, $clientBinding);
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

        return $this->respondWithMessage($bot, $message, 'Выберите компанию', [
            'inline_keyboard' => $keyboard,
        ]);
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
            return $this->respondWithMessage($bot, $message, 'Сначала привяжите аккаунт через ссылку из кабинета компании');
        }

        if (count($clientBindings) > 1) {
            return $this->respondWithMessage($bot, $message, 'У вас несколько компаний. Для операций сначала выберите компанию командой /set_cash');
        }

        $clientBinding = $clientBindings[0];
        if (!$clientBinding instanceof ClientBinding) {
            return new JsonResponse(['status' => 'ok']);
        }

        if (!$clientBinding) {
            return $this->respondWithMessage($bot, $message, 'Не найдена привязка. Отправьте /start.');
        }

        if ($clientBinding->getStatus() === ClientBinding::STATUS_BLOCKED) {
            return $this->respondWithMessage($bot, $message, 'Доступ заблокирован');
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

        return $this->respondWithMessage(
            $bot,
            $message,
            $this->buildReportMessage($subscription),
            $this->buildReportKeyboard($subscription)
        );
    }

    private function handleBindingSelectionCallback(TelegramBot $bot, array $callbackQuery, string $callbackData): Response
    {
        $from = $callbackQuery['from'] ?? [];
        $telegramUser = $this->findTelegramUserByCallback($from);
        if (!$telegramUser) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Сначала выполните привязку через ссылку /start');
        }

        $parts = explode(':', $callbackData);
        if (count($parts) !== 3 || $parts[2] !== 'set_cash') {
            return new JsonResponse(['status' => 'ok']);
        }

        [$prefix, $bindingId] = [$parts[0], $parts[1]];
        if ($prefix !== 'bind') {
            return new JsonResponse(['status' => 'ok']);
        }

        $clientBinding = $this->entityManager->getRepository(ClientBinding::class)->find($bindingId);
        if (!$clientBinding instanceof ClientBinding || $clientBinding->getTelegramUser()->getId() !== $telegramUser->getId()) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Привязка не найдена.');
        }

        if ($clientBinding->getStatus() === ClientBinding::STATUS_BLOCKED) {
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
        if (count($parts) !== 3) {
            return new JsonResponse(['status' => 'ok']);
        }

        [$prefix, $bindingId, $moneyAccountId] = $parts;
        if ($prefix !== 'cash') {
            return new JsonResponse(['status' => 'ok']);
        }

        $clientBinding = $this->entityManager->getRepository(ClientBinding::class)->find($bindingId);
        if (!$clientBinding instanceof ClientBinding || $clientBinding->getTelegramUser()->getId() !== $telegramUser->getId()) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Привязка не найдена.');
        }

        if ($clientBinding->getStatus() === ClientBinding::STATUS_BLOCKED) {
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

        if ($clientBinding->getStatus() === ClientBinding::STATUS_BLOCKED) {
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
        } elseif ($callbackData === 'rep:on') {
            $subscription->enable();
        } elseif ($callbackData === 'rep:off') {
            $subscription->disable();
        } else {
            return new JsonResponse(['status' => 'ok']);
        }

        $this->entityManager->flush();

        $text = $this->buildReportMessage($subscription);
        $replyMarkup = $this->buildReportKeyboard($subscription);

        // Обновляем существующее сообщение, если это возможно
        if (isset($callbackQuery['message']['message_id'], $callbackQuery['message']['chat']['id'])) {
            $chatId = (string) $callbackQuery['message']['chat']['id'];

            try {
                $response = $this->httpClient->request(
                    'POST',
                    sprintf('https://api.telegram.org/bot%s/editMessageText', $bot->getToken()),
                    [
                        'json' => [
                            'chat_id' => $chatId,
                            'message_id' => $callbackQuery['message']['message_id'],
                            'text' => $text,
                            'reply_markup' => $replyMarkup,
                        ],
                    ]
                );

                // Принудительно читаем ответ, чтобы ленивый HttpClient реально отправил запрос
                $statusCode = $response->getStatusCode();
                $content = $response->getContent(false);

                if ($statusCode !== 200) {
                    error_log(sprintf('Telegram editMessageText HTTP error: %d, chat=%s', $statusCode, $chatId));
                } else {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
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

        if ($clientBinding->getStatus() === ClientBinding::STATUS_BLOCKED) {
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
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $normalizedExtension = $extension !== '' ? '.' . strtolower($extension) : '';

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

    private function handleTextMessage(TelegramBot $bot, array $message, string $text): Response
    {
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

        if ($clientBinding->getStatus() === ClientBinding::STATUS_BLOCKED) {
            return $this->respondWithMessage($bot, $message, 'Доступ заблокирован администратором');
        }

        $moneyAccount = $clientBinding->getMoneyAccount();
        if (!$moneyAccount instanceof MoneyAccount) {
            return $this->respondWithMessage($bot, $message, 'Сначала выберите кассу: /set_cash');
        }

        $amount = $this->parseAmountFromText($text);
        if ($amount === null) {
            return $this->respondWithMessage($bot, $message, 'Формат: потратил 2500 на рекламу');
        }

        // MVP: определяем направление по ключевым словам, по умолчанию считаем расход
        $direction = $this->detectDirection($text);

        $now = new \DateTimeImmutable();

        if ($this->isRecentDuplicate($moneyAccount, $amount, $text, $now)) {
            return new JsonResponse(['status' => 'ok']);
        }

        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $clientBinding->getCompany(),
            $moneyAccount,
            $direction,
            $amount,
            $moneyAccount->getCurrency(),
            $now,
        );

        $transaction->setDescription($text);
        $transaction->setImportSource('telegram');

        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        $directionLabel = $direction === CashDirection::INFLOW ? 'доход' : 'расход';
        $formattedAmount = $this->formatAmountForMessage($amount, $moneyAccount->getCurrency());

        return $this->respondWithMessage(
            $bot,
            $message,
            sprintf('Записал %s %s', $directionLabel, $formattedAmount)
        );
    }

    private function parseAmountFromText(string $text): ?string
    {
        if (!preg_match('/\d[\d\s.,]*/u', $text, $matches)) {
            return null;
        }

        $raw = preg_replace('/\s+/u', '', $matches[0]);
        if ($raw === null || $raw === '') {
            return null;
        }

        $lastDot = strrpos($raw, '.');
        $lastComma = strrpos($raw, ',');
        $decimalSeparator = null;

        if ($lastDot !== false && $lastComma !== false) {
            $decimalSeparator = $lastDot > $lastComma ? '.' : ',';
        } elseif ($lastDot !== false) {
            $decimalSeparator = '.';
        } elseif ($lastComma !== false) {
            $decimalSeparator = ',';
        }

        if ($decimalSeparator === ',') {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace(',', '', $raw);
        }

        [$integerPart, $fractionalPart] = array_pad(explode('.', $raw, 2), 2, '');
        $integerPart = ltrim($integerPart, '0');
        $integerPart = $integerPart === '' ? '0' : $integerPart;

        $fractionalPart = substr($fractionalPart, 0, 2);
        $fractionalPart = str_pad($fractionalPart, 2, '0');

        return sprintf('%s.%s', $integerPart, $fractionalPart);
    }

    private function detectDirection(string $text): CashDirection
    {
        $normalized = mb_strtolower($text);

        if (preg_match('/потрат|расход|купил|оплат/u', $normalized)) {
            return CashDirection::OUTFLOW;
        }

        if (preg_match('/пришло|поступ|доход|получил/u', $normalized)) {
            return CashDirection::INFLOW;
        }

        // MVP: по умолчанию считаем расход
        return CashDirection::OUTFLOW;
    }

    private function isRecentDuplicate(MoneyAccount $account, string $amount, string $description, \DateTimeImmutable $now): bool
    {
        $since = $now->modify('-2 minutes');

        return (bool) $this->entityManager->getRepository(CashTransaction::class)
            ->createQueryBuilder('t')
            ->select('1')
            ->andWhere('t.moneyAccount = :account')
            ->andWhere('t.amount = :amount')
            ->andWhere('t.description = :description')
            ->andWhere('t.createdAt >= :since')
            ->setParameter('account', $account)
            ->setParameter('amount', $amount)
            ->setParameter('description', $description)
            ->setParameter('since', $since)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function formatAmountForMessage(string $amount, string $currency): string
    {
        [$integerPart, $fractionalPart] = array_pad(explode('.', $amount, 2), 2, '00');
        $fractionalPart = rtrim($fractionalPart, '0');
        $fractionalPart = $fractionalPart === '' ? '' : ',' . str_pad($fractionalPart, 2, '0');

        $formattedInteger = number_format((int) $integerPart, 0, '', ' ');

        $symbol = $currency === 'RUB' ? '₽' : $currency;

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

    private function respondWithMessage(TelegramBot $bot, array $message, string $text, ?array $replyMarkup = null): Response
    {
        // Отправляем простой ответ через Telegram API
        // chat.id лучше from.id: ответы должны приходить в чат (в том числе групповой), а не личные сообщения отправителя
        if (isset($message['chat']['id'])) {
            $chatId = (string) $message['chat']['id'];

            try {
                $response = $this->httpClient->request(
                    'POST',
                    sprintf('https://api.telegram.org/bot%s/sendMessage', $bot->getToken()),
                    [
                        'json' => array_filter(
                            [
                                'chat_id' => $chatId,
                                'text' => $text,
                                'reply_markup' => $replyMarkup,
                            ],
                            static fn ($value) => $value !== null,
                        ),
                    ],
                );

                // HttpClient ленивый: дергаем статус/тело, чтобы запрос точно ушел
                $statusCode = $response->getStatusCode();
                $content = $response->getContent(false);

                if ($statusCode !== 200) {
                    error_log(sprintf('Telegram sendMessage HTTP error: %d, chat=%s, text="%s"', $statusCode, $chatId, $text));
                } else {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
                        $description = $decoded['description'] ?? 'unknown error';
                        error_log(sprintf('Telegram sendMessage API error: %s, chat=%s, text="%s"', $description, $chatId, $text));
                    }
                }
            } catch (\Throwable $exception) {
                error_log(sprintf('Telegram sendMessage exception: %s', $exception->getMessage()));
            }
        }

        return new JsonResponse(['status' => 'ok']);
    }

    private function sendMoneyAccountSelection(TelegramBot $bot, array $message, ClientBinding $clientBinding): Response
    {
        if ($clientBinding->getStatus() === ClientBinding::STATUS_BLOCKED) {
            return $this->respondWithMessage($bot, $message, 'Доступ заблокирован администратором');
        }

        $moneyAccounts = $this->entityManager->getRepository(MoneyAccount::class)->findBy(
            ['company' => $clientBinding->getCompany()],
            ['sortOrder' => 'ASC', 'name' => 'ASC'],
        );

        if (!$moneyAccounts) {
            return $this->respondWithMessage($bot, $message, 'Доступные кассы не найдены.');
        }

        $lines = ['Выберите кассу:'];
        $keyboard = [];

        foreach ($moneyAccounts as $account) {
            if (!$account instanceof MoneyAccount) {
                continue;
            }

            $lines[] = sprintf('- %s (%s)', $account->getName(), $account->getCurrency());
            $keyboard[] = [
                [
                    'text' => $account->getName(),
                    'callback_data' => sprintf('cash:%s:%s', $clientBinding->getId(), $account->getId()),
                ],
            ];
        }

        return $this->respondWithMessage($bot, $message, implode("\n", $lines), [
            'inline_keyboard' => $keyboard,
        ]);
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

<?php

namespace App\Telegram\Controller;

use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\ReportSubscription;
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
    #[Route('/telegram/webhook', name: 'telegram_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        // Находим активного бота, чтобы принимать сообщения независимо от конкретного токена маршрута
        $bot = $this->botRepository->findActiveBot();
        if (!$bot || !$bot->isActive()) {
            throw $this->createNotFoundException();
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['status' => 'ignored']);
        }

        $callbackQuery = $payload['callback_query'] ?? null;
        if (is_array($callbackQuery)) {
            return new JsonResponse(['status' => 'ok']);
        }

        $message = $payload['message'] ?? null;
        $text = $message['text'] ?? null;

        // Обрабатываем только текстовые сообщения
        if (!\is_string($text)) {
            return new JsonResponse(['status' => 'ok']);
        }

        if (str_starts_with($text, '/start')) {
            return $this->handleStart($bot, $message, $text);
        }

        return new JsonResponse(['status' => 'ok']);
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
        $from = $message['from'] ?? [];
        $telegramUser = $this->syncTelegramUser($from);
        if (!$telegramUser) {
            return new JsonResponse(['status' => 'ignored']);
        }

        $clientBinding = $this->entityManager->getRepository(ClientBinding::class)->findOneBy([
            'company' => $bot->getCompany(),
            'bot' => $bot,
            'telegramUser' => $telegramUser,
        ]);

        if (!$clientBinding) {
            return $this->respondWithMessage($bot, $message, 'Не найдена привязка. Отправьте /start.');
        }

        if ($clientBinding->getStatus() === ClientBinding::STATUS_BLOCKED) {
            return $this->respondWithMessage($bot, $message, 'Доступ заблокирован');
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
                    'callback_data' => 'set_cash:'.$account->getId(),
                ],
            ];
        }

        $this->entityManager->flush();

        return $this->respondWithMessage($bot, $message, implode("\n", $lines), [
            'inline_keyboard' => $keyboard,
        ]);
    }

    private function handleCallbackQuery(TelegramBot $bot, array $callbackQuery): Response
    {
        $callbackData = $callbackQuery['data'] ?? null;
        if (!\is_string($callbackData)) {
            return new JsonResponse(['status' => 'ok']);
        }

        if (str_starts_with($callbackData, 'set_cash:')) {
            return $this->handleSetCashCallback($bot, $callbackQuery, substr($callbackData, strlen('set_cash:')));
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

        $clientBinding = $this->entityManager->getRepository(ClientBinding::class)->findOneBy([
            'company' => $bot->getCompany(),
            'bot' => $bot,
            'telegramUser' => $telegramUser,
        ]);

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

    private function handleSetCashCallback(TelegramBot $bot, array $callbackQuery, string $moneyAccountId): Response
    {
        $from = $callbackQuery['from'] ?? [];
        $telegramUser = $this->syncTelegramUser($from);
        if (!$telegramUser) {
            return new JsonResponse(['status' => 'ignored']);
        }

        $clientBinding = $this->entityManager->getRepository(ClientBinding::class)->findOneBy([
            'company' => $bot->getCompany(),
            'bot' => $bot,
            'telegramUser' => $telegramUser,
        ]);

        if (!$clientBinding) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Привязка не найдена.');
        }

        if ($clientBinding->getStatus() === ClientBinding::STATUS_BLOCKED) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Доступ заблокирован');
        }

        $moneyAccount = $this->entityManager->getRepository(MoneyAccount::class)->find($moneyAccountId);
        if (!$moneyAccount instanceof MoneyAccount) {
            return $this->respondWithMessage($bot, $callbackQuery, 'Касса не найдена.');
        }

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

        $clientBinding = $this->entityManager->getRepository(ClientBinding::class)->findOneBy([
            'company' => $bot->getCompany(),
            'bot' => $bot,
            'telegramUser' => $telegramUser,
        ]);

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
            $this->httpClient->request(
                'POST',
                sprintf('https://api.telegram.org/bot%s/editMessageText', $bot->getToken()),
                [
                    'json' => [
                        'chat_id' => (string) $callbackQuery['message']['chat']['id'],
                        'message_id' => $callbackQuery['message']['message_id'],
                        'text' => $text,
                        'reply_markup' => $replyMarkup,
                    ],
                ]
            );

            return new JsonResponse(['status' => 'ok']);
        }

        return $this->respondWithMessage($bot, $callbackQuery, $text, $replyMarkup);
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
        if (isset($message['from']['id'])) {
            $chatId = (string) $message['from']['id'];
            $this->httpClient->request('POST', sprintf('https://api.telegram.org/bot%s/sendMessage', $bot->getToken()), [
                'json' => array_filter(
                    [
                        'chat_id' => $chatId,
                        'text' => $text,
                        'reply_markup' => $replyMarkup,
                    ],
                    static fn ($value) => $value !== null,
                ),
            ]);
        }

        return new JsonResponse(['status' => 'ok']);
    }
}

<?php

namespace App\Telegram\Controller;

use App\Telegram\Entity\ClientBinding;
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

    #[Route('/telegram/bot/{token}', name: 'telegram_webhook', methods: ['POST'])]
    public function __invoke(string $token, Request $request): Response
    {
        // Находим бота по токену и проверяем активность
        $bot = $this->botRepository->findOneBy(['token' => $token]);
        if (!$bot || !$bot->isActive()) {
            throw $this->createNotFoundException();
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
        $text = $message['text'] ?? null;

        // Обрабатываем только текстовые сообщения
        if (!\is_string($text)) {
            return new JsonResponse(['status' => 'ok']);
        }

        if (str_starts_with($text, '/start')) {
            return $this->handleStart($bot, $message, $text);
        }

        if ($text === '/set_cash') {
            return $this->handleSetCash($bot, $message);
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

        // Проверяем принадлежность компании и актуальность ссылки
        if ($botLink->getCompany()->getId() !== $bot->getCompany()->getId()) {
            return $this->respondWithMessage($bot, $message, 'Ссылка недействительна для этого бота.');
        }

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
            'company' => $bot->getCompany(),
            'bot' => $bot,
            'telegramUser' => $telegramUser,
        ]);

        if (!$clientBinding) {
            $clientBinding = new ClientBinding(
                Uuid::uuid4()->toString(),
                $bot->getCompany(),
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
            'Вы привязаны. Теперь можете отправлять расходы/доходы.'
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

        return new JsonResponse(['status' => 'ok']);
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

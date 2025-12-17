<?php

namespace App\Telegram\Controller\Admin;

use App\Telegram\Entity\TelegramBot;
use App\Telegram\Form\TelegramBotType;
use App\Telegram\Repository\TelegramBotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/telegram/bots', name: 'admin_telegram_bot_')]
class TelegramBotController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(TelegramBotRepository $repository): Response
    {
        // Админка платформы: доступ только для суперадминов, без контекста активной компании
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        // Загружаем всех ботов сервиса, порядок — новые сверху
        $bots = $repository->findBy([], ['createdAt' => 'DESC']);

        return $this->render('telegram/admin/bot/index.html.twig', [
            'bots' => $bots,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        // Админка платформы: доступ только для суперадминов, без контекста активной компании
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        // Создаём нового бота без привязки к компании
        $bot = new TelegramBot(Uuid::uuid4()->toString(), '');

        $form = $this->createForm(TelegramBotType::class, $bot);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Сохраняем новую запись и возвращаемся к списку
            $entityManager->persist($bot);
            $entityManager->flush();

            $this->addFlash('success', 'Бот успешно создан');

            return $this->redirectToRoute('admin_telegram_bot_index');
        }

        return $this->render('telegram/admin/bot/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        string $id,
        Request $request,
        TelegramBotRepository $repository,
        EntityManagerInterface $entityManager,
    ): Response {
        // Админка платформы: доступ только для суперадминов, без контекста активной компании
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $bot = $repository->find($id);
        if (!$bot) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(TelegramBotType::class, $bot);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Обновляем дату изменения и сохраняем правки
            $bot->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', 'Бот обновлён');

            return $this->redirectToRoute('admin_telegram_bot_index');
        }

        return $this->render('telegram/admin/bot/edit.html.twig', [
            'form' => $form->createView(),
            'bot' => $bot,
        ]);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(
        string $id,
        Request $request,
        TelegramBotRepository $repository,
        EntityManagerInterface $entityManager,
    ): Response {
        // Админка платформы: доступ только для суперадминов, без контекста активной компании
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $bot = $repository->find($id);
        if (!$bot) {
            throw $this->createNotFoundException();
        }

        // Проверяем CSRF токен перед переключением активности
        if ($this->isCsrfTokenValid('toggle_bot'.$bot->getId(), (string) $request->request->get('_token'))) {
            $bot->setIsActive(!$bot->isActive());
            $bot->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', $bot->isActive() ? 'Бот активирован' : 'Бот деактивирован');
        }

        return $this->redirectToRoute('admin_telegram_bot_index');
    }

    #[Route('/webhook-health', name: 'webhook_health', methods: ['GET'])]
    public function webhookHealth(
        TelegramBotRepository $repository,
        HttpClientInterface $httpClient,
    ): Response {
        // Админка платформы: доступ только для суперадминов, без контекста активной компании
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        // Проверяем наличие активного бота, чтобы вызвать getWebhookInfo для актуального токена
        $bot = $repository->findActiveBot();
        if (!$bot) {
            $this->addFlash('danger', 'Нет активного бота');

            return $this->redirectToRoute('admin_telegram_bot_index');
        }

        try {
            // Запрашиваем информацию о текущем webhook через Telegram API: метод getWebhookInfo
            $response = $httpClient->request('GET', sprintf('https://api.telegram.org/bot%s/getWebhookInfo', $bot->getToken()));

            if ($response->getStatusCode() !== 200) {
                $this->addFlash('danger', sprintf('Telegram API вернул статус %d', $response->getStatusCode()));

                return $this->redirectToRoute('admin_telegram_bot_index');
            }

            $payload = $response->toArray(false);
        } catch (\Throwable $exception) {
            // В случае сетевой ошибки или недоступности API показываем администратору причину
            $this->addFlash('danger', sprintf('Не удалось проверить webhook: %s', $exception->getMessage()));

            return $this->redirectToRoute('admin_telegram_bot_index');
        }

        // Полезные поля ответа getWebhookInfo: url — текущий webhook, pending_update_count — сколько апдейтов ждут,
        // last_error_date / last_error_message — последняя ошибка доставки апдейтов, помогает диагностировать проблемы
        $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];
        $url = $result['url'] ?? null;
        $pending = $result['pending_update_count'] ?? null;
        $lastErrorDate = isset($result['last_error_date']) ? (int) $result['last_error_date'] : null;
        $lastErrorMessage = $result['last_error_message'] ?? null;

        $details = [];
        $details[] = sprintf('Webhook URL: %s', $url ?: 'не установлен');
        $details[] = sprintf('Ожидающих обновлений: %s', $pending ?? 'нет данных');

        if ($lastErrorDate || $lastErrorMessage) {
            $formattedDate = $lastErrorDate ? date('d.m.Y H:i:s', $lastErrorDate) : '—';
            $details[] = sprintf('Последняя ошибка: %s (%s)', $lastErrorMessage ?: 'сообщение отсутствует', $formattedDate);
        } else {
            $details[] = 'Ошибок не зафиксировано';
        }

        $this->addFlash('success', implode(' | ', $details));

        return $this->redirectToRoute('admin_telegram_bot_index');
    }
}

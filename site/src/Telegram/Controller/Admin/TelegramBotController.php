<?php

namespace App\Telegram\Controller\Admin;

use App\Telegram\Entity\TelegramBot;
use App\Telegram\Form\TelegramBotType;
use App\Telegram\Repository\TelegramBotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

        // Форма сама создаёт нового бота после выбора компании, поэтому не используем ActiveCompanyService
        $form = $this->createForm(TelegramBotType::class);
        $form->handleRequest($request);

        /** @var TelegramBot|null $bot */
        $bot = $form->getData();

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

        $form = $this->createForm(TelegramBotType::class, $bot, [
            // Компания задаётся при создании, поэтому в режиме редактирования не меняем её
            'lock_company' => true,
        ]);
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
}

<?php

namespace App\Telegram\Controller\Admin;

use App\Service\ActiveCompanyService;
use App\Telegram\Entity\TelegramBot;
use App\Telegram\Form\TelegramBotType;
use App\Telegram\Repository\TelegramBotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/telegram/bots', name: 'admin_telegram_bot_')]
class TelegramBotController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ActiveCompanyService $companyService, TelegramBotRepository $repository): Response
    {
        // Получаем текущую компанию из сессии пользователя
        $company = $companyService->getActiveCompany();

        // Подтягиваем всех ботов компании для отображения в списке
        $bots = $repository->findByCompany($company);

        return $this->render('telegram/admin/bot/index.html.twig', [
            'bots' => $bots,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        ActiveCompanyService $companyService,
        EntityManagerInterface $entityManager,
    ): Response {
        // Создаём нового бота, сразу привязанного к активной компании
        $company = $companyService->getActiveCompany();
        $bot = new TelegramBot(Uuid::uuid4()->toString(), $company, '');

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
        ActiveCompanyService $companyService,
        TelegramBotRepository $repository,
        EntityManagerInterface $entityManager,
    ): Response {
        // Проверяем, что бот принадлежит активной компании
        $company = $companyService->getActiveCompany();
        $bot = $repository->findOneByIdAndCompany($id, $company);
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
        ActiveCompanyService $companyService,
        TelegramBotRepository $repository,
        EntityManagerInterface $entityManager,
    ): Response {
        // Находим бота в пределах активной компании
        $company = $companyService->getActiveCompany();
        $bot = $repository->findOneByIdAndCompany($id, $company);
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

<?php

namespace App\Telegram\Controller\Admin;

use App\Telegram\Entity\BotLink;
use App\Telegram\Form\BotLinkType;
use App\Telegram\Repository\BotLinkRepository;
use App\Telegram\Repository\TelegramBotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/telegram/links', name: 'admin_telegram_link_')]
class BotLinkController extends AbstractController
{
    private const SCOPE_FINANCE = 'finance';

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(BotLinkRepository $repository): Response
    {
        // Админка платформы: доступ только для суперадминов, без привязки к активной компании
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $links = $repository->findBy([], ['createdAt' => 'DESC']);

        return $this->render('telegram/admin/link/index.html.twig', [
            'links' => $links,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        TelegramBotRepository $botRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        // Админка платформы: доступ только для суперадминов, без привязки к активной компании
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $form = $this->createForm(BotLinkType::class, [
            // Значение по умолчанию — 60 минут от текущего времени
            'expiresAt' => new \DateTimeImmutable('+60 minutes'),
        ]);

        $form->handleRequest($request);

        $createdLink = null;
        $deepLink = null;
        $usernameMissing = false;

        if ($form->isSubmitted() && $form->isValid()) {
            $botData = $form->get('bot')->getData();
            if (!$botData) {
                throw $this->createNotFoundException();
            }

            $botId = (string) $botData->getId();
            $bot = $botRepository->find($botId);
            if (!$bot) {
                throw $this->createNotFoundException();
            }

            /** @var \DateTimeImmutable $expiresAt */
            $expiresAt = $form->get('expiresAt')->getData();

            // Безопасно генерируем токен для deep-link
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

            $botLink = new BotLink(
                Uuid::uuid4()->toString(),
                $bot->getCompany(),
                $bot,
                $token,
                self::SCOPE_FINANCE,
                $expiresAt,
            );

            $entityManager->persist($botLink);
            $entityManager->flush();

            $createdLink = $botLink;

            if ($bot->getUsername()) {
                $username = ltrim($bot->getUsername(), '@');
                $deepLink = sprintf('https://t.me/%s?start=%s', $username, $botLink->getToken());
            } else {
                $usernameMissing = true;
            }

            $this->addFlash('success', 'Ссылка успешно создана');
        }

        return $this->render('telegram/admin/link/new.html.twig', [
            'form' => $form->createView(),
            'createdLink' => $createdLink,
            'deepLink' => $deepLink,
            'usernameMissing' => $usernameMissing,
        ]);
    }
}

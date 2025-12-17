<?php

namespace App\Telegram\Controller\Integration;

use App\Service\ActiveCompanyService;
use App\Telegram\Entity\BotLink;
use App\Telegram\Repository\TelegramBotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/integrations/telegram', name: 'telegram_integration_')]
class TelegramIntegrationController extends AbstractController
{
    private const SCOPE_FINANCE = 'finance';

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly TelegramBotRepository $telegramBotRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $bot = $this->telegramBotRepository->findActiveBot();

        $botLinkRepository = $this->entityManager->getRepository(BotLink::class);

        // Проверяем наличие использованных ссылок для компании
        $usedBotLink = $botLinkRepository
            ->createQueryBuilder('bl')
            ->andWhere('bl.company = :company')
            ->andWhere('bl.usedAt IS NOT NULL')
            ->setParameter('company', $company)
            ->orderBy('bl.usedAt', 'DESC')
            ->addOrderBy('bl.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $isBound = (bool) $usedBotLink;

        return $this->render('telegram/integration/index.html.twig', [
            'company' => $company,
            'bot' => $bot,
            'deepLink' => null,
            'deepLinkWeb' => null,
            'deepLinkApp' => null,
            'startCommand' => null,
            'usernameMissing' => false,
            'isBound' => $isBound,
        ]);
    }

    #[Route('/generate-link', name: 'generate_link', methods: ['POST'])]
    public function generateLink(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('telegram_generate_link', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF-токен.');

            return $this->redirectToRoute('telegram_integration_index');
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $bot = $this->telegramBotRepository->findActiveBot();

        if (!$bot) {
            $this->addFlash('warning', 'Администратор сервиса ещё не настроил Telegram-бота');

            return $this->redirectToRoute('telegram_integration_index');
        }

        // Безопасно генерируем токен для deep-link
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $expiresAt = new \DateTimeImmutable('+60 minutes');

        $botLink = new BotLink(
            Uuid::uuid4()->toString(),
            $company,
            $bot,
            $token,
            self::SCOPE_FINANCE,
            $expiresAt,
        );

        $this->entityManager->persist($botLink);
        $this->entityManager->flush();

        $deepLink = null;
        $deepLinkWeb = null;
        $deepLinkApp = null;
        $startCommand = null;
        $usernameMissing = false;

        $botLinkRepository = $this->entityManager->getRepository(BotLink::class);

        // Проверяем наличие использованных ссылок для компании
        $usedBotLink = $botLinkRepository
            ->createQueryBuilder('bl')
            ->andWhere('bl.company = :company')
            ->andWhere('bl.usedAt IS NOT NULL')
            ->setParameter('company', $company)
            ->orderBy('bl.usedAt', 'DESC')
            ->addOrderBy('bl.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $isBound = (bool) $usedBotLink;

        if ($bot->getUsername()) {
            $username = ltrim($bot->getUsername(), '@');
            $token = $botLink->getToken();

            $deepLink = sprintf('https://t.me/%s?start=%s', $username, $token);
            $deepLinkWeb = sprintf('https://t.me/%s?start=%s', $username, $token);
            $deepLinkApp = sprintf('tg://resolve?domain=%s&start=%s', $username, $token);
            $startCommand = sprintf('/start %s', $token);
        } else {
            $usernameMissing = true;
        }

        $this->addFlash('success', 'Ссылка привязки создана.');

        return $this->render('telegram/integration/index.html.twig', [
            'company' => $company,
            'bot' => $bot,
            'deepLink' => $deepLink,
            'deepLinkWeb' => $deepLinkWeb,
            'deepLinkApp' => $deepLinkApp,
            'startCommand' => $startCommand,
            'usernameMissing' => $usernameMissing,
            'isBound' => $isBound,
        ]);
    }
}

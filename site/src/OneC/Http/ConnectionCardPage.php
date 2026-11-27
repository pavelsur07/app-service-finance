<?php

declare(strict_types=1);

namespace App\OneC\Http;

use App\Company\Security\ModuleAccess;
use App\OneC\Infrastructure\Repository\OneCConnectionRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/** Карточка подключения; после перевыпуска — с новым токеном, без кеширования. */
final readonly class ConnectionCardPage
{
    public function __construct(
        private OneCConnectionRepository $connections,
        private Environment $twig,
        private ClockInterface $clock,
        private Security $security,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    public function render(string $connectionId, string $companyId, #[\SensitiveParameter] ?string $secret = null): Response
    {
        $connection = $this->connections->getForCompany($connectionId, $companyId);

        return new Response($this->twig->render('onec/card.html.twig', [
            'connection' => $connection,
            'organizations' => $this->connections->organizations($connection),
            'packages' => $this->connections->packages($connection),
            'secret' => $secret,
            'now' => $this->clock->now(),
            'can_write' => $this->security->isGranted(ModuleAccess::FINANCE_WRITE),
            'export_token' => $this->csrf->getToken('onec_export_'.$connection->id)->getValue(),
        ]), headers: ['Cache-Control' => 'private, no-store']);
    }
}

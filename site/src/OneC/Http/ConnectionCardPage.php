<?php

declare(strict_types=1);

namespace App\OneC\Http;

use App\OneC\Infrastructure\Query\ConnectionQuery;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Карточка подключения; после перевыпуска — с новым токеном, без кеширования. */
final readonly class ConnectionCardPage
{
    public function __construct(private ConnectionQuery $connections, private Environment $twig, private ClockInterface $clock)
    {
    }

    public function render(string $ownerId, string $connectionId, #[\SensitiveParameter] ?string $secret = null): Response
    {
        $connection = $this->connections->getOwned($ownerId, $connectionId);

        return new Response($this->twig->render('onec/card.html.twig', [
            'connection' => $connection,
            'organizations' => $this->connections->organizations($connection),
            'packages' => $this->connections->packages($connection),
            'secret' => $secret,
            'now' => $this->clock->now(),
        ]), headers: ['Cache-Control' => 'private, no-store']);
    }
}

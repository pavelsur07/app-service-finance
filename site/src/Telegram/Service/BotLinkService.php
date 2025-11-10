<?php

declare(strict_types=1);

namespace App\Telegram\Service;

use App\Entity\Company;
use App\Telegram\Entity\BotLink;
use App\Telegram\Entity\TelegramBot;
use App\Telegram\Repository\BotLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\ByteString;

final class BotLinkService
{
    public const DEFAULT_SCOPE = 'finance';
    private const MIN_TTL_SECONDS = 30 * 60; // 30 минут
    private const MAX_TTL_SECONDS = 60 * 60; // 60 минут
    private const DEFAULT_TTL_SECONDS = 45 * 60; // по умолчанию 45 минут
    private const DEFAULT_LEEWAY_SECONDS = 20; // допуск на рассинхрон часов

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BotLinkRepository $repo,
        private readonly string $appSecret, // %kernel.secret%
    ) {
    }

    /**
     * Создать deep-link (scope=finance) для конкретного бота.
     * Возвращает готовый URL и метаданные.
     */
    public function createFinanceLink(Company $company, TelegramBot $bot, ?int $ttlSeconds = null): array
    {
        $ttl = $this->normalizeTtl($ttlSeconds);
        $now = new \DateTimeImmutable();
        $expiresAt = $now->add(new \DateInterval(sprintf('PT%dS', $ttl)));

        $token = $this->makeSignedToken(
            companyId: (string) $company->getId(),
            botId: (string) $bot->getId(),
            scope: self::DEFAULT_SCOPE,
            expiresAt: $expiresAt
        );

        $link = new BotLink($company, $bot, $token, self::DEFAULT_SCOPE, $expiresAt);
        $this->em->persist($link);
        $this->em->flush();

        $botUsername = $bot->getUsername(); // геттер должен быть в вашей сущности TelegramBot
        $url = sprintf('https://t.me/%s?start=%s', $botUsername, $token);

        return [
            'url' => $url,
            'token' => $token,
            'expiresAt' => $expiresAt,
            'entityId' => $link->getId(),
        ];
    }

    /**
     * Проверить токен и пометить одноразовое использование.
     *
     * @return array{companyId:string,botId:string,scope:string}
     */
    public function validateAndConsume(
        string $token,
        TelegramBot $expectedBot,
        ?string $expectedScope = self::DEFAULT_SCOPE,
        int $leewaySeconds = self::DEFAULT_LEEWAY_SECONDS,
    ): array {
        // 1) Проверяем подпись и распаковываем payload
        $payload = $this->verifySignedToken($token);

        // 2) Быстрые синтаксические проверки payload (exp/leeway)
        $nowTs = (new \DateTimeImmutable())->getTimestamp();
        if ((int) $payload['exp'] < $nowTs - max(0, $leewaySeconds)) {
            throw new \DomainException('Token expired (payload).');
        }
        if (null !== $expectedScope && $payload['scp'] !== $expectedScope) {
            throw new \DomainException('Invalid token scope.');
        }
        if ($payload['bid'] !== (string) $expectedBot->getId()) {
            throw new \DomainException('Token is not issued for this bot.');
        }

        // 3) Транзакционная проверка состояния записи и пометка usedAt
        $this->em->beginTransaction();
        try {
            $link = $this->repo->findOneByTokenForUpdate($token);
            if (!$link) {
                throw new \DomainException('Token not found.');
            }

            if ($link->isUsed()) {
                throw new \DomainException('Token already used.');
            }

            if ($link->isExpired(new \DateTimeImmutable(), $leewaySeconds)) {
                throw new \DomainException('Token expired (db).');
            }

            // Доп. сверки контекста
            if ((string) $link->getBot()->getId() !== $payload['bid']) {
                throw new \DomainException('Token-bot mismatch.');
            }
            if ($link->getScope() !== $payload['scp']) {
                throw new \DomainException('Token-scope mismatch.');
            }

            $link->markUsed();
            $this->em->flush();
            $this->em->commit();

            return [
                'companyId' => $payload['cid'],
                'botId' => $payload['bid'],
                'scope' => $payload['scp'],
            ];
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    // =========================
    // ВНУТРЕННИЕ МЕТОДЫ
    // =========================

    private function normalizeTtl(?int $ttlSeconds): int
    {
        $ttl = $ttlSeconds ?? self::DEFAULT_TTL_SECONDS;
        if ($ttl < self::MIN_TTL_SECONDS) {
            $ttl = self::MIN_TTL_SECONDS;
        }
        if ($ttl > self::MAX_TTL_SECONDS) {
            $ttl = self::MAX_TTL_SECONDS;
        }

        return $ttl;
    }

    /**
     * Подписываем payload: base64url(json).base64url(hmac_sha256).
     */
    private function makeSignedToken(string $companyId, string $botId, string $scope, \DateTimeImmutable $expiresAt): string
    {
        $payload = [
            'cid' => $companyId,
            'bid' => $botId,
            'scp' => $scope,
            'exp' => $expiresAt->getTimestamp(),
            'n' => ByteString::fromRandom(8)->toString(),
            'ver' => 1,
        ];

        $json = json_encode($payload, \JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            throw new \RuntimeException('Failed to encode token payload.');
        }

        $payloadB64 = $this->b64urlEncode($json);
        $sigRaw = hash_hmac('sha256', $payloadB64, $this->appSecret, true);
        $sigB64 = $this->b64urlEncode($sigRaw);

        return $payloadB64.'.'.$sigB64;
    }

    /**
     * Проверяем подпись, возвращаем payload.
     *
     * @return array{cid:string,bid:string,scp:string,exp:int,n:string,ver:int}
     */
    private function verifySignedToken(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw new \DomainException('Malformed token.');
        }
        [$payloadB64, $sigB64] = $parts;

        $calc = hash_hmac('sha256', $payloadB64, $this->appSecret, true);
        $sig = $this->b64urlDecode($sigB64);

        if (!hash_equals($calc, $sig)) {
            throw new \DomainException('Invalid token signature.');
        }

        $json = $this->b64urlDecode($payloadB64);
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        foreach (['cid', 'bid', 'scp', 'exp', 'n', 'ver'] as $k) {
            if (!array_key_exists($k, $data)) {
                throw new \DomainException('Invalid token payload.');
            }
        }

        return [
            'cid' => (string) $data['cid'],
            'bid' => (string) $data['bid'],
            'scp' => (string) $data['scp'],
            'exp' => (int) $data['exp'],
            'n' => (string) $data['n'],
            'ver' => (int) $data['ver'],
        ];
    }

    private function b64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $b64): string
    {
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode(strtr($b64, '-_', '+/'), true);
        if (false === $out) {
            throw new \DomainException('Invalid base64url input.');
        }

        return $out;
    }
}

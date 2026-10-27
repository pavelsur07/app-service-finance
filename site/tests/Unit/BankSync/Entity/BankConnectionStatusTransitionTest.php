<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Entity;

use App\BankSync\Entity\BankConnection;
use App\BankSync\Enum\BankCode;
use App\BankSync\Enum\BankConnectionStatus;
use App\BankSync\Exception\BankConnectionStatusTransitionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BankConnectionStatusTransitionTest extends TestCase
{
    private const OPERATIONS = ['reauthorize', 'markNeedsReauth', 'markRevoked', 'disable', 'enable'];

    /**
     * Матрица stage-3.md §2: статус «из» × операция → статус «в» или отказ (null).
     *
     * @return iterable<string, array{BankConnectionStatus, string, BankConnectionStatus|null}>
     */
    public static function matrix(): iterable
    {
        $expected = [
            'active' => ['reauthorize' => 'active', 'markNeedsReauth' => 'needs_reauth', 'markRevoked' => 'revoked', 'disable' => 'disabled', 'enable' => null],
            'needs_reauth' => ['reauthorize' => 'active', 'markNeedsReauth' => 'needs_reauth', 'markRevoked' => 'revoked', 'disable' => 'disabled', 'enable' => null],
            'revoked' => ['reauthorize' => 'active', 'markNeedsReauth' => null, 'markRevoked' => null, 'disable' => null, 'enable' => null],
            'disabled' => ['reauthorize' => 'active', 'markNeedsReauth' => null, 'markRevoked' => 'revoked', 'disable' => null, 'enable' => 'active'],
        ];

        foreach ($expected as $from => $operations) {
            foreach (self::OPERATIONS as $operation) {
                $to = $operations[$operation];
                yield $from.' → '.$operation => [
                    BankConnectionStatus::from($from),
                    $operation,
                    null === $to ? null : BankConnectionStatus::from($to),
                ];
            }
        }
    }

    #[DataProvider('matrix')]
    public function testTransition(BankConnectionStatus $from, string $operation, ?BankConnectionStatus $to): void
    {
        $connection = self::connectionIn($from);
        $before = $connection->getCredentialsEncrypted();

        if (null === $to) {
            $this->expectException(BankConnectionStatusTransitionException::class);
        }

        self::apply($connection, $operation);

        self::assertSame($to, $connection->getStatus());
        if ('reauthorize' === $operation) {
            self::assertNotSame($before, $connection->getCredentialsEncrypted());
            self::assertNull($connection->getStatusReason());
        }
    }

    public function testNeedsReauthReportsOnlyTheFirstTransition(): void
    {
        $connection = self::connectionIn(BankConnectionStatus::ACTIVE);

        self::assertTrue($connection->markNeedsReauth('refresh_rejected'));
        self::assertFalse($connection->markNeedsReauth('refresh_rejected'));
        self::assertSame('refresh_rejected', $connection->getStatusReason());
        self::assertFalse($connection->canSync());
    }

    public function testOnlyActiveConnectionSyncs(): void
    {
        foreach (BankConnectionStatus::cases() as $status) {
            self::assertSame(BankConnectionStatus::ACTIVE === $status, self::connectionIn($status)->canSync(), $status->value);
        }
    }

    public function testTokenRefreshKeepsStatus(): void
    {
        $connection = self::connectionIn(BankConnectionStatus::NEEDS_REAUTH);

        $connection->replaceCredentials('{"new":true}', 'v1');

        self::assertSame(BankConnectionStatus::NEEDS_REAUTH, $connection->getStatus());
    }

    public function testConsentExpiryIsStoredAsDayAndResetByNewSignIn(): void
    {
        $connection = self::connectionIn(BankConnectionStatus::ACTIVE);
        $connection->recordAuthorizedSubject('user-1');
        $connection->updateConsentExpiresAt(new \DateTimeImmutable('2027-10-26 15:30:00'));

        self::assertSame('user-1', $connection->getExternalSubject());
        self::assertEquals(new \DateTimeImmutable('2027-10-26 00:00:00'), $connection->getConsentExpiresAt());

        $connection->reauthorize('{"fresh":true}', 'v1', 'user-2');

        self::assertSame('user-2', $connection->getExternalSubject());
        self::assertNull($connection->getConsentExpiresAt(), 'новое согласие — новый срок со сверкой');
    }

    public function testExternalSubjectLengthIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::connectionIn(BankConnectionStatus::ACTIVE)->recordAuthorizedSubject(str_repeat('a', 256));
    }

    private static function connectionIn(BankConnectionStatus $status): BankConnection
    {
        $connection = new BankConnection('0199f0a0-0000-7000-8000-000000000001', BankCode::ALFA, 'https://bank.example', '{"old":true}', 'v1');
        match ($status) {
            BankConnectionStatus::ACTIVE => null,
            BankConnectionStatus::NEEDS_REAUTH => $connection->markNeedsReauth('test'),
            BankConnectionStatus::REVOKED => $connection->markRevoked('test'),
            BankConnectionStatus::DISABLED => $connection->disable(),
        };

        return $connection;
    }

    private static function apply(BankConnection $connection, string $operation): void
    {
        match ($operation) {
            'reauthorize' => $connection->reauthorize('{"fresh":true}', 'v1'),
            'markNeedsReauth' => $connection->markNeedsReauth('test'),
            'markRevoked' => $connection->markRevoked('test'),
            'disable' => $connection->disable(),
            'enable' => $connection->enable(),
            default => throw new \LogicException($operation),
        };
    }
}

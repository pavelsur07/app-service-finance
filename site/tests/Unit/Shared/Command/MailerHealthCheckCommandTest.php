<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Command;

use App\Shared\Command\MailerHealthCheckCommand;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;

final class MailerHealthCheckCommandTest extends TestCase
{
    private const DSN = 'smtp://user:secret@smtp.example.com:465';

    public function testSuccessfulHandshakeReturnsSuccess(): void
    {
        $transport = $this->createMock(SmtpTransport::class);
        // start() = connect + EHLO + STARTTLS + AUTH; письмо не отправляется.
        $transport->expects(self::once())->method('start');
        $transport->expects(self::atLeastOnce())->method('stop');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $tester = new CommandTester(
            new MailerHealthCheckCommand($this->factoryReturning($transport), self::DSN, $logger),
        );

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('OK', $tester->getDisplay());
    }

    public function testHandshakeFailureLogsErrorAndReturnsFailure(): void
    {
        $transport = $this->createMock(SmtpTransport::class);
        $transport->method('start')->willThrowException(new TransportException('535 Authentication failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Mailer healthcheck FAILED', self::callback(
                static fn (array $context): bool => array_key_exists('duration_ms', $context)
                    && $context['exception'] instanceof TransportException,
            ));

        $tester = new CommandTester(
            new MailerHealthCheckCommand($this->factoryReturning($transport), self::DSN, $logger),
        );

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    public function testNonSmtpDsnIsSkippedWithoutAlert(): void
    {
        // null://, sendmail и т.п. проверять нечем — это не сбой, алертить нельзя.
        $factory = $this->createMock(TransportFactoryInterface::class);
        $factory->method('supports')->willReturn(false);
        $factory->expects(self::never())->method('create');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $tester = new CommandTester(
            new MailerHealthCheckCommand($factory, 'null://null', $logger),
        );

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('SKIPPED', $tester->getDisplay());
    }

    private function factoryReturning(SmtpTransport $transport): TransportFactoryInterface
    {
        $factory = $this->createMock(TransportFactoryInterface::class);
        $factory->method('supports')->willReturn(true);
        $factory->method('create')->willReturn($transport);

        return $factory;
    }
}

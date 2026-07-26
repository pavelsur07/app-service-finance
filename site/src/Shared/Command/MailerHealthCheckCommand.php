<?php

declare(strict_types=1);

namespace App\Shared\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;

/**
 * Синтетическая проверка живости SMTP: connect → EHLO → STARTTLS → AUTH → QUIT.
 * Письмо НЕ отправляется — handshake ловит тот же отказ (протухший пароль, закрытый порт,
 * блокировка провайдером) без спама, репутационных рисков и ящика-приёмника.
 *
 * Запускается кроном (supercronic) раз в час. При сбое — logger->error → GlitchTip алерт, exit 1.
 * Проактивно ловит мёртвый транспорт до того, как это увидит первый пользователь: реальные
 * письма алертят только когда их кто-то пытается отправить.
 */
#[AsCommand(
    name: 'app:mailer:healthcheck',
    description: 'Синтетическая проверка живости SMTP-транспорта (connect→EHLO→AUTH, без отправки письма).',
)]
final class MailerHealthCheckCommand extends Command
{
    public function __construct(
        // mailer.transports — коллекция Transports без start(), а Transport (фабрика) объявлен final
        // и немокируем. Фабрика SMTP приходит по интерфейсу: мокируется и даёт готовый supports().
        #[Autowire(service: 'mailer.transport_factory.smtp')] private readonly TransportFactoryInterface $smtpFactory,
        #[Autowire(env: 'MAILER_DSN')] private readonly string $mailerDsn,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startedAt = microtime(true);

        try {
            $dsn = Dsn::fromString($this->mailerDsn);
            $transport = $this->smtpFactory->supports($dsn) ? $this->smtpFactory->create($dsn) : null;

            if (!$transport instanceof SmtpTransport) {
                // null://, sendmail и прочие не-SMTP транспорты проверять нечем — это не сбой.
                $output->writeln('mailer healthcheck SKIPPED — MAILER_DSN не SMTP');

                return Command::SUCCESS;
            }

            $transport->start();
            $transport->stop();
        } catch (\Throwable $exception) {
            // В контексте только длительность и исключение: DSN и пароль не логируем.
            // Сообщение SMTP-ошибки содержит код ответа сервера, не креды.
            $this->logger->error('Mailer healthcheck FAILED', [
                'duration_ms' => $this->elapsedMs($startedAt),
                'exception' => $exception,
            ]);
            $output->writeln(sprintf('<error>mailer healthcheck FAILED: %s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('mailer healthcheck OK (%d ms)', $this->elapsedMs($startedAt)));

        return Command::SUCCESS;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}

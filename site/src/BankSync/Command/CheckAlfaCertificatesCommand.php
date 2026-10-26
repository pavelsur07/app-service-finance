<?php

declare(strict_types=1);

namespace App\BankSync\Command;

use App\BankSync\Domain\Certificate\CertificateExpiryPolicy;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaTlsConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ночной гейт сроков сертификатов канала Альфы: CA серверов банка из репозитория и
 * клиентский сертификат из окружения (лист и цепочка, если она в том же файле).
 * Read-only, без сети. Действия при срабатывании — `docs/workflow/health-gates.md`.
 *
 * Клиентский сертификат называется по серийному номеру, не по subject: в его CN —
 * ФИО представителя компании.
 */
#[AsCommand(name: 'bank-sync:alfa:certificates-check', description: 'Проверить сроки сертификатов канала Альфы (клиентский и CA)')]
final class CheckAlfaCertificatesCommand extends Command
{
    public function __construct(
        private readonly AlfaTlsConfig $tlsConfig,
        private readonly CertificateExpiryPolicy $policy,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = $this->clock->now();
        /** @var array<string, list<string>> $byLevel */
        $byLevel = ['ok' => [], 'warning' => [], 'error' => []];
        $checked = 0;

        $sources = ['CA' => $this->tlsConfig->caBundlePath()];
        $clientPath = $this->tlsConfig->clientCertificatePath();
        if (null !== $clientPath) {
            $sources['client'] = $clientPath;
        } else {
            $output->writeln('client: not configured ('.AlfaTlsConfig::CERT_PATH_ENV.' is empty), not checked');
        }

        foreach ($sources as $source => $path) {
            $certificates = $this->readCertificates($path);
            if ([] === $certificates) {
                $line = sprintf('%s: file is not readable or has no PEM certificates', $source);
                $byLevel['error'][] = $line;
                $output->writeln('[error] '.$line);
                continue;
            }

            foreach ($certificates as $index => $certificate) {
                ++$checked;
                $level = $this->policy->level($certificate['notAfter'], $now);
                $line = sprintf(
                    '%s #%d %s: until %s, %d days left',
                    $source,
                    $index + 1,
                    'CA' === $source ? $certificate['name'] : 'serial '.$certificate['serial'],
                    $certificate['notAfter']->format('Y-m-d'),
                    $this->policy->daysLeft($certificate['notAfter'], $now),
                );
                $byLevel[$level->value][] = $line;
                $output->writeln(sprintf('[%s] %s', $level->value, $line));
            }
        }

        $output->writeln(sprintf(
            'Checked %d certificate(s): %d ok, %d warning, %d error.',
            $checked,
            \count($byLevel['ok']),
            \count($byLevel['warning']),
            \count($byLevel['error']),
        ));

        if ([] !== $byLevel['error']) {
            // Текст постоянный: одно событие в GlitchTip, детали — в контексте.
            $this->logger->error('Alfa TLS certificates: expired, expiring within 14 days or unreadable', [
                'certificates' => $byLevel['error'],
                'warning_certificates' => $byLevel['warning'],
            ]);

            return Command::FAILURE;
        }
        if ([] !== $byLevel['warning']) {
            $this->logger->warning('Alfa TLS certificates: expiring within 30 days', ['certificates' => $byLevel['warning']]);
        } else {
            $this->logger->info('Alfa TLS certificates checked', ['checked' => $checked, 'client_configured' => null !== $clientPath]);
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<array{name: string, serial: string, notAfter: \DateTimeImmutable}>
     */
    private function readCertificates(string $path): array
    {
        $pem = is_readable($path) ? file_get_contents($path) : false;
        if (false === $pem || !preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $blocks)) {
            return [];
        }

        $certificates = [];
        foreach ($blocks[0] as $block) {
            // @ — openssl пишет warning о неразобранном блоке; false проверяется ниже.
            $parsed = @openssl_x509_parse($block);
            if (false === $parsed || !\is_int($parsed['validTo_time_t'] ?? null)) {
                return [];
            }
            $subject = $parsed['subject'] ?? [];
            $commonName = \is_array($subject) ? ($subject['CN'] ?? 'unknown') : 'unknown';
            $certificates[] = [
                'name' => \is_string($commonName) ? $commonName : 'unknown',
                'serial' => \is_string($parsed['serialNumberHex'] ?? null) ? $parsed['serialNumberHex'] : 'unknown',
                'notAfter' => (new \DateTimeImmutable())->setTimestamp($parsed['validTo_time_t']),
            ];
        }

        return $certificates;
    }
}

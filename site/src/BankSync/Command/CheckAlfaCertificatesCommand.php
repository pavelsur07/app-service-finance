<?php

declare(strict_types=1);

namespace App\BankSync\Command;

use App\BankSync\Domain\Certificate\CertificateExpiryPolicy;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaIdConfig;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaTlsConfig;
use App\BankSync\Infrastructure\Security\PemCertificates;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ночной гейт сроков сертификатов Альфы: CA серверов банка из репозитория, клиентский
 * сертификат канала из окружения (лист и цепочка, если она в том же файле) и сертификаты
 * проверки подписи `id_token` Alfa ID по средам. Read-only, без сети. Действия при срабатывании — `docs/workflow/health-gates.md`.
 *
 * Клиентский сертификат называется по серийному номеру, не по subject: в его CN —
 * ФИО представителя компании.
 */
#[AsCommand(name: 'bank-sync:alfa:certificates-check', description: 'Проверить сроки сертификатов канала Альфы (клиентский и CA)')]
final class CheckAlfaCertificatesCommand extends Command
{
    public function __construct(
        private readonly AlfaTlsConfig $tlsConfig,
        private readonly AlfaIdConfig $idConfig,
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
        foreach ($this->idConfig->signingCertificatePaths() as $environment => $path) {
            if (null === $path) {
                $output->writeln(sprintf('id-signing-%s: not configured, not checked', $environment));
                continue;
            }
            $sources['id-signing-'.$environment] = $path;
        }

        foreach ($sources as $source => $path) {
            $certificates = $this->readCertificates($path);
            if ([] === $certificates) {
                $line = sprintf('%s: file is not readable or not a complete PEM certificate chain', $source);
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
                    'client' === $source ? 'serial '.$certificate['serial'] : $certificate['name'],
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
            $this->logger->error('Alfa certificates: expired, expiring within 14 days or unreadable', [
                'certificates' => $byLevel['error'],
                'warning_certificates' => $byLevel['warning'],
            ]);

            return Command::FAILURE;
        }
        if ([] !== $byLevel['warning']) {
            $this->logger->warning('Alfa certificates: expiring within 30 days', ['certificates' => $byLevel['warning']]);
        } else {
            $this->logger->info('Alfa certificates checked', ['checked' => $checked, 'client_configured' => null !== $clientPath]);
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<array{name: string, serial: string, notAfter: \DateTimeImmutable}>
     */
    private function readCertificates(string $path): array
    {
        $parsed = PemCertificates::parse(PemCertificates::readFile($path) ?? '');
        if (null === $parsed) {
            return [];
        }

        $certificates = [];
        foreach ($parsed as $certificate) {
            $info = openssl_x509_parse($certificate);
            if (false === $info || !\is_int($info['validTo_time_t'] ?? null)) {
                return [];
            }
            $subject = $info['subject'] ?? [];
            $commonName = \is_array($subject) ? ($subject['CN'] ?? 'unknown') : 'unknown';
            $certificates[] = [
                'name' => \is_string($commonName) ? $commonName : 'unknown',
                'serial' => \is_string($info['serialNumberHex'] ?? null) ? $info['serialNumberHex'] : 'unknown',
                'notAfter' => (new \DateTimeImmutable())->setTimestamp($info['validTo_time_t']),
            ];
        }

        return $certificates;
    }
}

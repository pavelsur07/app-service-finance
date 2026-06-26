<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

trait OzonAccrualCommandHelperTrait
{
    private function optionalUuidOption(InputInterface $input, string $name): ?string
    {
        $value = $this->optionalStringOption($input, $name);
        if (null === $value) {
            return null;
        }

        Assert::uuid($value, sprintf('Invalid --%s UUID.', $name));

        return $value;
    }

    private function optionalDateOption(InputInterface $input, string $name): ?\DateTimeImmutable
    {
        $value = $this->optionalStringOption($input, $name);
        if (null === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('--%s must be a valid YYYY-MM-DD date.', $name));
        }

        return $date;
    }

    private function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $value = trim((string) $input->getOption($name));

        return '' === $value ? null : $value;
    }

    private function intOption(InputInterface $input, string $name, int $min, int $max): int
    {
        $value = (string) $input->getOption($name);
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer from %d to %d.', $name, $min, $max));
        }

        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer from %d to %d.', $name, $min, $max));
        }

        return $number;
    }

    /**
     * @param list<array<string, mixed>> $targets
     */
    private function printTargets(SymfonyStyle $io, array $targets): void
    {
        if ([] === $targets) {
            $io->writeln('No done Ozon accrual by-day raw records found for the selected filters.');

            return;
        }

        $io->table(
            ['companyId', 'shopRef', 'windowFrom', 'windowTo', 'rawRecords'],
            array_map(static fn (array $target): array => [
                (string) $target['company_id'],
                (string) $target['shop_ref'],
                (string) $target['window_from'],
                (string) $target['window_to'],
                (string) $target['raw_count'],
            ], $targets),
        );
    }

    /**
     * @param array<string, int> $metrics
     */
    private function printMetrics(SymfonyStyle $io, array $metrics): void
    {
        $io->table(
            ['metric', 'value'],
            array_map(
                static fn (string $metric, int $value): array => [$metric, (string) $value],
                array_keys($metrics),
                array_values($metrics),
            ),
        );
    }

    /**
     * @param array<string, int> $metrics
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function metricRows(string $action, array $metrics): array
    {
        return array_map(
            static fn (string $metric, int $value): array => [$action, $metric, (string) $value],
            array_keys($metrics),
            array_values($metrics),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\BankSync\Command;

use App\BankSync\Application\DTO\BankConnectionFormData;
use App\BankSync\Application\OAuthTokenRefresher;
use App\BankSync\Domain\BankAccess;
use App\BankSync\Enum\BankCode;
use App\BankSync\Enum\BankDirection;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankChannelNotConfiguredException;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaApiClient;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaBankProvider;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaFixtureAnonymizer;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaStatementMapper;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaTraceIdRecorder;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Repository\BankConnectionRepository;
use App\Shared\Security\Exception\DecryptionException;
use App\Shared\Security\Exception\InvalidEncryptedPayloadException;
use App\Shared\Security\Exception\MissingEncryptionKeyException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Проверка песочницы Альфы на живом API (Stage 7): только чтение, ничего не пишет в БД и
 * не обновляет токены. Счета; выписка дня дважды — стабилен ли ключ операции (`uuid`,
 * `transactionId`); обороты дня — сходятся ли с выпиской; `X-TraceId` для банка.
 * Номера счетов в выводе — последние 4 цифры.
 */
#[AsCommand(name: 'bank-sync:alfa:sandbox-check', description: 'Проверить API песочницы Альфы на подключении: счета, ключ операции, обороты (только чтение)')]
final class AlfaSandboxCheckCommand extends Command
{
    /**
     * @param (\Closure(int): void)|null $pause пауза между чтениями дня; в тестах — без ожидания
     */
    public function __construct(
        private readonly BankConnectionRepository $connections,
        private readonly BankCredentialsCodec $credentialsCodec,
        private readonly OAuthTokenRefresher $tokenRefresher,
        private readonly AlfaApiClient $apiClient,
        private readonly AlfaStatementMapper $mapper,
        private readonly AlfaFixtureAnonymizer $anonymizer,
        private readonly AlfaTraceIdRecorder $traceIds,
        private readonly ClockInterface $clock,
        private readonly ?\Closure $pause = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'ID компании подключения')
            ->addOption('connection', null, InputOption::VALUE_REQUIRED, 'ID подключения к песочнице')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'День выписки, Y-m-d (по умолчанию — вчера)')
            ->addOption('pause', null, InputOption::VALUE_REQUIRED, 'Пауза между двумя чтениями дня, секунд', '5')
            ->addOption('save-fixtures', null, InputOption::VALUE_REQUIRED, 'Каталог для обезличенных ответов банка');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = (string) $input->getOption('company');
        $connectionId = (string) $input->getOption('connection');
        $connection = $this->connections->findOneByIdAndCompanyId($connectionId, $companyId);
        if (null === $connection) {
            $output->writeln('<error>Connection not found in this company.</error>');

            return Command::FAILURE;
        }
        // Банк пока один (Альфа); с новым банком здесь появится проверка кода банка.
        if (BankConnectionFormData::SANDBOX_URL !== $connection->getBaseUrl()) {
            $output->writeln('<error>Only an Alfa sandbox connection can be checked.</error>');

            return Command::FAILURE;
        }
        if (!$connection->canSync()) {
            $output->writeln(sprintf('<error>Connection is %s: sign in to the bank again.</error>', $connection->getStatus()->value));

            return Command::FAILURE;
        }
        $date = $this->date($input);
        if (null === $date) {
            $output->writeln('<error>Invalid --date, expected Y-m-d.</error>');

            return Command::INVALID;
        }

        try {
            // Команда только читает: истекающий токен не обновляет, обновит загрузка.
            if ($this->tokenRefresher->needsRefresh($connection)) {
                $output->writeln('<error>Access token expires: press «Запустить импорт сейчас» (it refreshes tokens) and run the check within the hour.</error>');

                return Command::FAILURE;
            }
            $access = new BankAccess($connection->getId(), $connection->getBankCode(), $connection->getBaseUrl(), $this->credentialsCodec->token($connection));
        } catch (DecryptionException|InvalidEncryptedPayloadException|MissingEncryptionKeyException) {
            $output->writeln('<error>Connection credentials cannot be decrypted.</error>');

            return Command::FAILURE;
        }

        $fixturesDir = $input->getOption('save-fixtures');
        $fixtures = \is_string($fixturesDir) && '' !== $fixturesDir ? $this->fixtureWriter($fixturesDir) : null;
        if (\is_string($fixturesDir) && '' !== $fixturesDir && null === $fixtures) {
            $output->writeln('<error>Fixtures directory cannot be created.</error>');

            return Command::FAILURE;
        }
        $this->traceIds->reset();

        try {
            $ok = $this->check($access, $date, max(0, (int) $input->getOption('pause')), $fixtures, $output);
        } catch (BankApiUnavailableException|BankApiRequestFailedException|BankChannelNotConfiguredException $exception) {
            $output->writeln(sprintf('<error>Bank request failed: %s</error>', $exception->getMessage()));
            $ok = false;
        }

        $output->writeln('');
        $output->writeln('X-TraceId: '.([] === $this->traceIds->all() ? '—' : implode(', ', $this->traceIds->all())));
        $output->writeln(sprintf('Checked at %s; outgoing IP — `curl -s https://ifconfig.me` on the same host.', $this->clock->now()->format(\DATE_ATOM)));
        $output->writeln($ok ? '<info>Sandbox check passed.</info>' : '<error>Sandbox check found problems.</error>');

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    private function check(BankAccess $access, \DateTimeImmutable $date, int $pauseSeconds, ?\Closure $fixtures, OutputInterface $output): bool
    {
        $rawAccounts = $this->apiClient->getAccounts($access);
        null !== $fixtures && $fixtures('accounts.json', $rawAccounts);
        $accounts = $this->mapper->mapAccounts($rawAccounts);
        $output->writeln(sprintf('Accounts: %d (skipped without number or currency: %d), pages: %s', \count($accounts->accounts), $accounts->skippedAccounts, $this->totalPages($rawAccounts)));

        // Пропущенный счёт или ни одного проверенного — проверка неполная, не «успех».
        $ok = 0 === $accounts->skippedAccounts;
        $checked = 0;
        foreach ($accounts->accounts as $index => $account) {
            $label = '…'.substr($account->accountNumber, -4);
            if (!$account->enabled) {
                $output->writeln(sprintf('Account %s: disabled in the bank, skipped', $label));
                continue;
            }

            $first = $this->readDay($access, $account->accountNumber, $date, $fixtures, sprintf('statement-%d-read1', $index));
            if ($pauseSeconds > 0) {
                ($this->pause ?? sleep(...))($pauseSeconds);
            }
            $second = $this->readDay($access, $account->accountNumber, $date, $fixtures, sprintf('statement-%d-read2', $index));
            $rawSummary = $this->apiClient->getSummary($access, $account->accountNumber, $date);
            null !== $fixtures && $fixtures(sprintf('summary-%d.json', $index), $rawSummary);
            $summary = $this->mapper->mapSummary($rawSummary);

            $uuidStable = $first['uuid'] === $second['uuid'];
            $transactionIdStable = $first['transactionId'] === $second['transactionId'];
            $balanced = $summary->isConsistent();
            $countsMatch = (null === $summary->creditTransactions || $summary->creditTransactions === $first['credits'])
                && (null === $summary->debitTransactions || $summary->debitTransactions === $first['debits']);
            $turnoverMatches = 0 === bccomp($summary->creditTurnover, $first['creditSum'], 2) && 0 === bccomp($summary->debitTurnover, $first['debitSum'], 2);

            $output->writeln(sprintf('Account %s (%s), %s:', $label, $account->currency, $date->format('Y-m-d')));
            $output->writeln(sprintf('  lines: read1=%d read2=%d, skipped=%d, pages=%d', \count($first['uuid']), \count($second['uuid']), $first['skipped'], $first['pages']));
            $output->writeln(sprintf('  uuid stable: %s; transactionId stable: %s (with transactionId: %d)', $this->yesNo($uuidStable), $this->yesNo($transactionIdStable), \count($first['transactionId'])));
            $output->writeln(sprintf('  summary: opening + credit − debit = closing: %s; operations count matches: %s; turnover matches lines: %s', $this->yesNo($balanced), $this->yesNo($countsMatch), $this->yesNo($turnoverMatches)));
            $ok = $ok && $uuidStable && $balanced && $countsMatch && $turnoverMatches && 0 === $first['skipped'];
            ++$checked;
        }
        if (0 === $checked) {
            $output->writeln('<error>No enabled account to check: nothing was verified.</error>');
        }

        return $ok && $checked > 0;
    }

    /**
     * @return array{uuid: list<string>, transactionId: list<string>, credits: int, debits: int, creditSum: string, debitSum: string, skipped: int, pages: int}
     */
    private function readDay(BankAccess $access, string $accountNumber, \DateTimeImmutable $date, ?\Closure $fixtures, string $fixturePrefix): array
    {
        $day = ['uuid' => [], 'transactionId' => [], 'credits' => 0, 'debits' => 0, 'creditSum' => '0.00', 'debitSum' => '0.00', 'skipped' => 0, 'pages' => 0];
        for ($pageNumber = 1;; ++$pageNumber) {
            if ($pageNumber > AlfaBankProvider::MAX_PAGES) {
                // Как в загрузке: неполный день не должен пройти сверку.
                throw new BankApiRequestFailedException(BankCode::ALFA->value, null);
            }
            $raw = $this->apiClient->getTransactions($access, $accountNumber, $date, $pageNumber);
            null !== $fixtures && $fixtures(sprintf('%s-p%d.json', $fixturePrefix, $pageNumber), $raw);
            $page = $this->mapper->mapStatementPage($raw);
            $day['pages'] = $pageNumber;
            $day['skipped'] += $page->skippedLines;
            foreach ($page->lines as $line) {
                $day['uuid'][] = $line->externalId;
                if (null !== $line->transactionId) {
                    $day['transactionId'][] = $line->transactionId;
                }
                if (BankDirection::CREDIT === $line->direction) {
                    ++$day['credits'];
                    $day['creditSum'] = bcadd($day['creditSum'], $line->amount, 2);
                } else {
                    ++$day['debits'];
                    $day['debitSum'] = bcadd($day['debitSum'], $line->amount, 2);
                }
            }
            if (!$page->hasNextPage) {
                break;
            }
        }
        sort($day['uuid']);
        sort($day['transactionId']);

        return $day;
    }

    /**
     * Запись обезличенного ответа в каталог; одна соль на прогон — замены согласованы между
     * файлами прогона. null — каталог не создать.
     *
     * @return (\Closure(string, array<mixed>): bool)|null
     */
    private function fixtureWriter(string $dir): ?\Closure
    {
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            return null;
        }
        $salt = bin2hex(random_bytes(16));

        return function (string $name, array $raw) use ($dir, $salt): bool {
            $json = json_encode($this->anonymizer->anonymize($raw, $salt), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

            return false !== file_put_contents(rtrim($dir, '/').'/'.$name, $json."\n");
        };
    }

    private function date(InputInterface $input): ?\DateTimeImmutable
    {
        $raw = $input->getOption('date');
        if (!\is_string($raw) || '' === $raw) {
            return $this->clock->now()->setTime(0, 0)->modify('-1 day');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return false !== $date && $date->format('Y-m-d') === $raw ? $date : null;
    }

    /**
     * @param array<mixed> $rawAccounts
     */
    private function totalPages(array $rawAccounts): string
    {
        $meta = $rawAccounts['Meta'] ?? null;

        return \is_array($meta) && \is_int($meta['totalPages'] ?? null) ? (string) $meta['totalPages'] : 'not given';
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'NO';
    }
}

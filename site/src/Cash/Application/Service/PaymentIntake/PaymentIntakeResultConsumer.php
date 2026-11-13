<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\PaymentIntake;

use App\Cash\Application\PaymentIntake\PaymentIntakeAction;
use App\Cash\Repository\PaymentIntake\PaymentIntakeDraftRepository;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Enum\AiResultStatus;
use App\ServiceGateway\Facade\AiTaskResultConsumerInterface;

/** Gateway owns transaction, usage accounting and ack. This consumer only changes the locked draft. */
final readonly class PaymentIntakeResultConsumer implements AiTaskResultConsumerInterface
{
    public function __construct(private PaymentIntakeDraftRepository $drafts, private FinancialResponsibilityCenterFacade $centers)
    {
    }

    public function supports(string $taskType): bool
    {
        return PaymentIntakeAction::TASK_TYPE === $taskType;
    }

    public function consume(AiTaskResult $result): void
    {
        $draft = $this->drafts->getByTask($result->companyId, $result->taskId);
        if (null === $draft) {
            return;
        }
        if (\in_array($result->status, [AiResultStatus::FAILED, AiResultStatus::REJECTED], true)) {
            $draft->fail($result->taskId, $result->errorCode ?? $result->status->value);

            return;
        }
        if (null === $result->output || !$this->validOutput($result->output, $draft->getText())) {
            $draft->fail($result->taskId, 'contract_violation');

            return;
        }
        $output = $this->sanitizeReferences($result->output, $draft->getPayload());
        if ($draft->acceptResult($result->taskId, $output)) {
            $general = $this->centers->findGeneralPair($result->companyId);
            $draft->applyGeneralPairDefault($general?->projectDirectionId, $general?->responsibilityCenterId);
        }
    }

    /**
     * @param array<string, mixed> $output
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    private function sanitizeReferences(array $output, array $snapshot): array
    {
        $categories = array_column($snapshot['categories'] ?? [], 'direction', 'id');
        $projects = array_column($snapshot['projects'] ?? [], 'id', 'id');
        $centers = array_column($snapshot['cfos'] ?? [], 'id', 'id');
        $pairs = [];
        foreach ($snapshot['allowed_pairs'] ?? [] as $pair) {
            $pairs[$pair['project_id'].'/'.$pair['cfo_id']] = true;
        }
        foreach ($output['items'] as &$item) {
            $category = $item['category_id'];
            if (null !== $category && (!isset($categories[$category]) || ('any' !== $categories[$category] && $categories[$category] !== $item['direction']))) {
                $item['category_id'] = null;
                $item['questions'][] = 'Выберите доступную статью для направления платежа.';
            }
            $project = $item['project_id'];
            $center = $item['cfo_id'];
            if ((null !== $project || null !== $center)
                && (null === $project || null === $center || !isset($projects[$project], $centers[$center], $pairs[$project.'/'.$center]))) {
                $item['project_id'] = null;
                $item['cfo_id'] = null;
                $item['questions'][] = 'Укажите доступную пару проекта и ЦФО.';
            }
            $item['questions'] = array_values(array_unique($item['questions']));
        }
        unset($item);

        return $output;
    }

    /** @param array<string, mixed> $output */
    private function validOutput(array $output, string $sourceText): bool
    {
        if (!$this->exactKeys($output, ['intent', 'items', 'target_ref', 'questions']) || !\in_array($output['intent'] ?? null, ['payments', 'correction', 'no_payment'], true)
            || !\is_array($output['items'] ?? null) || !array_is_list($output['items']) || \count($output['items']) > 50
            || !$this->strings($output['questions'] ?? null)
            || (!\array_key_exists('target_ref', $output)) || (null !== $output['target_ref'] && !$this->boundedString($output['target_ref'], 1, 64))) {
            return false;
        }
        if ('no_payment' === $output['intent'] && [] !== $output['items']) {
            return false;
        }
        $refs = [];
        foreach ($output['items'] as $item) {
            if (!\is_array($item) || !$this->exactKeys($item, ['ref', 'text_evidence', 'amount_evidence', 'amount', 'currency', 'date', 'direction', 'execution_status', 'description', 'category_id', 'project_id', 'cfo_id', 'questions']) || !$this->boundedString($item['ref'] ?? null, 1, 64) || isset($refs[$item['ref']])
                || !$this->boundedString($item['text_evidence'] ?? null, 1, 16000)
                || (null !== ($item['amount_evidence'] ?? null) && !$this->boundedString($item['amount_evidence'], 1, 16000))
                || !$this->boundedString($item['description'] ?? null, 0, 1024) || !$this->strings($item['questions'] ?? null)
                || !\in_array($item['execution_status'] ?? null, ['paid', 'planned', 'unknown'], true)) {
                return false;
            }
            foreach (['amount', 'currency', 'date', 'direction', 'category_id', 'project_id', 'cfo_id'] as $key) {
                if (!\array_key_exists($key, $item) || (null !== $item[$key] && !\is_string($item[$key]))) {
                    return false;
                }
            }
            if ((null !== $item['amount'] && 1 !== preg_match('/^[0-9]{1,16}\.[0-9]{2}$/D', $item['amount']))
                || (null !== $item['currency'] && 1 !== preg_match('/^[A-Z]{3}$/D', $item['currency']))
                || (null !== $item['date'] && !$this->validDate($item['date']))
                || !\in_array($item['direction'], [null, 'inflow', 'outflow'], true)) {
                return false;
            }
            foreach (['category_id', 'project_id', 'cfo_id'] as $key) {
                if (null !== $item[$key] && !$this->boundedString($item[$key], 1, 64)) {
                    return false;
                }
            }
            if (!str_contains($sourceText, $item['text_evidence'])
                || (null !== $item['amount_evidence'] && !str_contains($item['text_evidence'], $item['amount_evidence']))
                || (null !== $item['amount'] && null === $item['amount_evidence'])) {
                return false;
            }
            $refs[$item['ref']] = true;
        }

        return true;
    }

    private function strings(mixed $values): bool
    {
        return \is_array($values) && array_is_list($values) && \count($values) <= 50 && [] === array_filter($values, fn ($value): bool => !$this->boundedString($value, 1, 500));
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $keys
     */
    private function exactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private function boundedString(mixed $value, int $minimum, int $maximum): bool
    {
        return \is_string($value) && mb_strlen($value) >= $minimum && mb_strlen($value) <= $maximum;
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value;
    }
}

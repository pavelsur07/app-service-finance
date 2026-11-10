<?php

declare(strict_types=1);

namespace App\Cash\Http;

use App\Cash\Service\Import\File\CashFileMappingValidator;
use App\Company\Entity\User;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class CashFileImportPage extends AbstractController
{
    public function __construct(private readonly CashFileMappingValidator $mappingValidator)
    {
    }

    public function actorId(?UserInterface $user): ?string
    {
        if ($user instanceof User) {
            return $user->getId();
        }
        $id = $user?->getUserIdentifier();

        return null !== $id && Uuid::isValid($id) ? $id : null;
    }

    /** @param array<string, mixed> $payload */
    public function invalidateDescriptionPlan(SessionInterface $session, array $payload): void
    {
        unset($payload['description_plan'], $payload['description_context'], $payload['selected_descriptions'], $payload['description_filter']);
        $session->set('cash_file_import', $payload);
    }

    /** @return array<string, string|null>|null */
    public function buildMappingFromRequest(Request $request): ?array
    {
        $input = $request->request->all();
        $mapping = [];
        foreach (CashFileMappingValidator::FIELDS as $field) {
            $mapping[$field] = $input[$field.'_column'] ?? null;
        }
        try {
            $this->mappingValidator->validate($mapping);
        } catch (\App\Cash\Exception\InvalidCashFileMappingException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return null;
        }
        foreach ($mapping as $field => $value) {
            $mapping[$field] = is_string($value) && '' !== trim($value) ? trim($value) : null;
        }
        if (null !== $mapping['inflow'] && null !== $mapping['outflow']) {
            $mapping['amount'] = null;
        } elseif (null !== $mapping['amount']) {
            $mapping['inflow'] = $mapping['outflow'] = null;
        }

        return $mapping;
    }

    /**
     * @return array{name: string, mapping: string, options: string}
     */
    public function extractFormData(Request $request): array
    {
        return ['name' => trim((string) $request->request->get('name')), 'mapping' => (string) $request->request->get('mapping'), 'options' => (string) $request->request->get('options')];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodeJson(string $payload, string $label): ?array
    {
        if ('' === trim($payload)) {
            return [];
        }
        $decoded = json_decode($payload, true);
        if (\JSON_ERROR_NONE !== json_last_error() || !is_array($decoded)) {
            $this->addFlash('error', sprintf('Некорректный JSON для %s.', $label));

            return null;
        }
        if ('маппинга' === $label) {
            try {
                $this->mappingValidator->validate($decoded);
            } catch (\App\Cash\Exception\InvalidCashFileMappingException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return null;
            }
        }

        return $decoded;
    }
}

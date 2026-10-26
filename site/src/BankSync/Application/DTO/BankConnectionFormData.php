<?php

declare(strict_types=1);

namespace App\BankSync\Application\DTO;

use App\BankSync\Entity\BankConnection;
use App\BankSync\Enum\BankCode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Данные формы подключения. Изменяемый: его заполняет форма Symfony. Токен — только при
 * вводе; из сущности не читается, и пустой токен при редактировании оставляет прежний.
 */
final class BankConnectionFormData
{
    public const SANDBOX_URL = 'https://sandbox.alfabank.ru';
    public const PRODUCTION_URL = 'https://baas.alfabank.ru';

    #[Assert\NotNull]
    public ?BankCode $bankCode = BankCode::ALFA;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: [self::SANDBOX_URL, self::PRODUCTION_URL])]
    public ?string $baseUrl = self::PRODUCTION_URL;

    #[Assert\Length(max: 4096)]
    public ?string $token = null;

    public bool $isActive = true;

    public static function fromConnection(BankConnection $connection): self
    {
        $data = new self();
        $data->bankCode = $connection->getBankCode();
        $data->baseUrl = $connection->getBaseUrl();
        $data->isActive = $connection->isActive();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['bankCode' => $this->bankCode?->value, 'baseUrl' => $this->baseUrl, 'isActive' => $this->isActive];
    }
}

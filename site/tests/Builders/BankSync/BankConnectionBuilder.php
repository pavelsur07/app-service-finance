<?php

declare(strict_types=1);

namespace App\Tests\Builders\BankSync;

use App\BankSync\Entity\BankConnection;
use App\BankSync\Enum\BankCode;
use App\BankSync\Infrastructure\Security\EncryptedBankCredentials;
use Webmozart\Assert\Assert;

final class BankConnectionBuilder
{
    public const DEFAULT_ID = '44444444-4444-7444-8444-444444444444';
    public const DEFAULT_COMPANY_ID = '11111111-1111-7111-8111-111111111111';

    private string $id = self::DEFAULT_ID;
    private string $companyId = self::DEFAULT_COMPANY_ID;
    private string $baseUrl = 'https://bank.test';
    private bool $isActive = true;
    private EncryptedBankCredentials $credentials;

    private function __construct()
    {
        $this->credentials = new EncryptedBankCredentials('{"not":"decryptable"}', 'test');
    }

    public static function aConnection(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        Assert::uuid($id);
        $copy = clone $this;
        $copy->id = $id;

        return $copy;
    }

    public function withIndex(int $index): self
    {
        Assert::range($index, 0, 999999999999);

        return $this->withId(sprintf('44444444-4444-7444-8444-%012d', $index));
    }

    public function withCompanyId(string $companyId): self
    {
        Assert::uuid($companyId);
        $copy = clone $this;
        $copy->companyId = $companyId;

        return $copy;
    }

    public function withBaseUrl(string $baseUrl): self
    {
        $copy = clone $this;
        $copy->baseUrl = $baseUrl;

        return $copy;
    }

    public function inactive(): self
    {
        $copy = clone $this;
        $copy->isActive = false;

        return $copy;
    }

    /**
     * Шифротекст из `BankCredentialsCodec`; без него токен не расшифровать.
     */
    public function withCredentials(EncryptedBankCredentials $credentials): self
    {
        $copy = clone $this;
        $copy->credentials = $credentials;

        return $copy;
    }

    public function build(): BankConnection
    {
        $connection = new BankConnection(
            $this->companyId,
            BankCode::ALFA,
            $this->baseUrl,
            $this->credentials->storageJson,
            $this->credentials->keyVersion,
            $this->id,
        );
        if (!$this->isActive) {
            $connection->disable();
        }

        return $connection;
    }
}

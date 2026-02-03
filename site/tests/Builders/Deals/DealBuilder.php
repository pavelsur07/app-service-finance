<?php

declare(strict_types=1);

namespace App\Tests\Builders\Deals;

use App\Company\Entity\Company;
use App\Deals\Entity\Deal;
use App\Deals\Enum\DealChannel;
use App\Deals\Enum\DealStatus;
use App\Deals\Enum\DealType;
use App\Tests\Builders\Company\CompanyBuilder;

final class DealBuilder
{
    public const DEFAULT_DEAL_ID = '44444444-4444-4444-4444-444444444444';
    public const DEFAULT_NUMBER = 'DEAL-2024-000001';
    public const DEFAULT_RECOGNIZED_AT = '2024-02-01';
    public const DEFAULT_CREATED_AT = '2024-02-01 00:00:00+00:00';

    private string $id;
    private Company $company;
    private string $number;
    private DealType $type;
    private DealChannel $channel;
    private \DateTimeImmutable $recognizedAt;
    private ?string $title;
    private ?\DateTimeImmutable $occurredAt;
    private ?string $currency;
    private ?DealStatus $status;

    private function __construct()
    {
        $this->id = self::DEFAULT_DEAL_ID;
        $this->company = CompanyBuilder::aCompany()->build();
        $this->number = self::DEFAULT_NUMBER;
        $this->type = DealType::SALE;
        $this->channel = DealChannel::SHOP;
        $this->recognizedAt = new \DateTimeImmutable(self::DEFAULT_RECOGNIZED_AT);
        $this->title = null;
        $this->occurredAt = null;
        $this->currency = null;
        $this->status = null;
    }

    public static function aDeal(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function forCompany(Company $company): self
    {
        $clone = clone $this;
        $clone->company = $company;

        return $clone;
    }

    public function withNumber(string $number): self
    {
        $clone = clone $this;
        $clone->number = $number;

        return $clone;
    }

    public function withType(DealType $type): self
    {
        $clone = clone $this;
        $clone->type = $type;

        return $clone;
    }

    public function withChannel(DealChannel $channel): self
    {
        $clone = clone $this;
        $clone->channel = $channel;

        return $clone;
    }

    public function withRecognizedAt(\DateTimeImmutable $recognizedAt): self
    {
        $clone = clone $this;
        $clone->recognizedAt = $recognizedAt;

        return $clone;
    }

    public function withTitle(?string $title): self
    {
        $clone = clone $this;
        $clone->title = $title;

        return $clone;
    }

    public function withOccurredAt(?\DateTimeImmutable $occurredAt): self
    {
        $clone = clone $this;
        $clone->occurredAt = $occurredAt;

        return $clone;
    }

    public function withCurrency(?string $currency): self
    {
        $clone = clone $this;
        $clone->currency = $currency;

        return $clone;
    }

    public function withStatus(DealStatus $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function build(): Deal
    {
        $deal = new Deal(
            $this->id,
            $this->company,
            $this->number,
            $this->type,
            $this->channel,
            $this->recognizedAt,
        );

        $deal->setTitle($this->title);
        $deal->setOccurredAt($this->occurredAt);
        $deal->setCurrency($this->currency);

        if ($this->status) {
            $deal->setStatus($this->status);
        }

        $createdAt = new \DateTimeImmutable(self::DEFAULT_CREATED_AT);
        $createdAtProperty = new \ReflectionProperty(Deal::class, 'createdAt');
        $createdAtProperty->setAccessible(true);
        $createdAtProperty->setValue($deal, $createdAt);

        $updatedAtProperty = new \ReflectionProperty(Deal::class, 'updatedAt');
        $updatedAtProperty->setAccessible(true);
        $updatedAtProperty->setValue($deal, $createdAt);

        return $deal;
    }
}

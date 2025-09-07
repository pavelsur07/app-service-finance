<?php

namespace App\Entity\Ozon;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ozon_order_status_history')]
#[ORM\UniqueConstraint(name: 'uniq_order_status_changed', columns: ['order_id', 'status', 'changed_at'])]
#[ORM\Index(name: 'idx_order_changed', columns: ['order_id', 'changed_at'])]
#[ORM\Index(name: 'idx_status_history_status', columns: ['status'])]
class OzonOrderStatusHistory
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: OzonOrder::class)]
    #[ORM\JoinColumn(nullable: false)]
    private OzonOrder $order;

    #[ORM\Column(length: 255)]
    private string $status;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $changedAt;

    #[ORM\Column(type: 'json')]
    private array $rawEvent = [];

    public function __construct(string $id, OzonOrder $order)
    {
        $this->id = $id;
        $this->order = $order;
        $this->changedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?string { return $this->id; }
    public function getOrder(): OzonOrder { return $this->order; }
    public function setOrder(OzonOrder $order): void { $this->order = $order; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }
    public function setChangedAt(\DateTimeImmutable $changedAt): void { $this->changedAt = $changedAt; }
    public function getRawEvent(): array { return $this->rawEvent; }
    public function setRawEvent(array $rawEvent): void { $this->rawEvent = $rawEvent; }
}

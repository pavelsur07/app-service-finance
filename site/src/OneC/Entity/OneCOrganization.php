<?php

declare(strict_types=1);

namespace App\OneC\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Организация относится к компании своего подключения; enabled — привязана к обмену. */
#[ORM\Entity]
#[ORM\Table(name: 'onec_organization')]
class OneCOrganization
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: OneCConnection::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    public OneCConnection $connection;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    public string $guid;

    #[ORM\Column(length: 255)]
    public string $name;

    #[ORM\Column(length: 10, nullable: true)]
    public ?string $inn = null;

    #[ORM\Column(length: 9, nullable: true)]
    public ?string $kpp = null;

    #[ORM\Column]
    public bool $enabled = false;

    #[ORM\Column]
    public bool $present = true;
}

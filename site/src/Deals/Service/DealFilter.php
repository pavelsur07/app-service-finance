<?php

namespace App\Deals\Service;

use App\Deals\Enum\DealChannel;
use App\Deals\Enum\DealStatus;
use App\Entity\Counterparty;

class DealFilter
{
    public ?\DateTimeInterface $dateFrom = null;

    public ?\DateTimeInterface $dateTo = null;

    public ?DealStatus $status = null;

    public ?DealChannel $channel = null;

    public ?Counterparty $counterparty = null;

    public int $page = 1;

    public int $limit = 20;
}

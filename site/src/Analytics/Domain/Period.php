<?php

namespace App\Analytics\Domain;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Period
{
    private DateTimeImmutable $from;
    private DateTimeImmutable $to;

    public function __construct(DateTimeImmutable $from, DateTimeImmutable $to)
    {
        $normalizedFrom = $from->setTime(0, 0);
        $normalizedTo = $to->setTime(0, 0);

        if ($normalizedFrom > $normalizedTo) {
            throw new InvalidArgumentException('Period "from" must be less or equal to "to".');
        }

        $this->from = $normalizedFrom;
        $this->to = $normalizedTo;
    }

    public function getFrom(): DateTimeImmutable
    {
        return $this->from;
    }

    public function getTo(): DateTimeImmutable
    {
        return $this->to;
    }

    public function days(): int
    {
        return $this->from->diff($this->to)->days + 1;
    }

    public function prevPeriod(): self
    {
        $days = $this->days();
        $prevTo = $this->from->sub(new DateInterval('P1D'));
        $prevFrom = $prevTo->sub(new DateInterval(sprintf('P%dD', $days - 1)));

        return new self($prevFrom, $prevTo);
    }
}

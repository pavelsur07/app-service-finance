<?php

namespace App\Banking\Dto;

final class Cursor
{
    public function __construct(
        public ?string $sinceId = null,
        public ?\DateTimeImmutable $sinceDate = null,
    ) {
    }
}

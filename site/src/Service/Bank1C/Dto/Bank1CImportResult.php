<?php

namespace App\Service\Bank1C\Dto;

class Bank1CImportResult
{
    public int $total = 0;
    public int $created = 0;
    public int $duplicates = 0;
    /** @var string[] */
    public array $errors = [];
    /** @var array<int,array<string,string>> */
    public array $samples = [];
}

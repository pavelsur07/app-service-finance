<?php

namespace App\Company\DTO;

use App\Company\Entity\CompanyInvite;
use App\Company\Entity\CompanyMember;

final class CompanyInviteResult
{
    public function __construct(
        public readonly string $type,
        public readonly ?CompanyInvite $invite = null,
        public readonly ?CompanyMember $member = null,
        public readonly ?string $plainToken = null,
    ) {
    }
}

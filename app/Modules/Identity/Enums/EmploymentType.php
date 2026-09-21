<?php

namespace App\Modules\Identity\Enums;

enum EmploymentType: string
{
    case Permanent = 'permanent';
    case Contract = 'contract';
    case Freelance = 'freelance';
    case Intern = 'intern';
}

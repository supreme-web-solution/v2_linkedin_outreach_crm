<?php

namespace App\V2\Ai\Enums;

enum AiToolPermission: string
{
    case Read = 'read';
    case Prepare = 'prepare';
    case Execute = 'execute';
}

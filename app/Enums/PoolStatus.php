<?php

namespace App\Enums;

enum PoolStatus: string
{
    case AVAILABLE = 'AVAILABLE';
    case ASSIGNED = 'ASSIGNED';
    case COOLDOWN = 'COOLDOWN';
    case EXHAUSTED = 'EXHAUSTED';
}

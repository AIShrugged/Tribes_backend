<?php

namespace App\Enums;

enum DecisionSourceType: string
{
    case Meeting = 'meeting';
    case Manual  = 'manual';
    case Chat    = 'chat';
}
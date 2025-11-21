<?php

namespace App\Enums;

enum SourceAuthType: string
{
    case NONE = 'none';
    case OAUTH2 = 'oauth2';
    case BASIC = 'basic';
}

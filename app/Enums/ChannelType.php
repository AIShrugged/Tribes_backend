<?php

namespace App\Enums;

enum ChannelType: string
{
    case Web = 'web';
    case TelegramPrivate = 'telegram_private';
    case TelegramGroup = 'telegram_group';
}

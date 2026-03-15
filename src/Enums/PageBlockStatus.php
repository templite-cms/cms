<?php

namespace Templite\Cms\Enums;

enum PageBlockStatus: string
{
    case Published = 'published';
    case Draft = 'draft';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Опубликован',
            self::Draft => 'Черновик',
            self::Hidden => 'Скрыт',
        };
    }
}

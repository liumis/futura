<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TodoStatus: string implements HasLabel
{
    case New = 'new';
    case InProgress = 'inprogress';
    case Confirm = 'confirm';
    case Returned = 'returned';
    case Done = 'done';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::New => 'New',
            self::InProgress => 'In progress',
            self::Confirm => 'Confirm',
            self::Returned => 'Returned',
            self::Done => 'Done',
        };
    }
}

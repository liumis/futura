<?php

namespace App\Support;

use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;

/**
 * When a Select would only ever offer one choice for the current user/data,
 * render a Hidden field on the same state path instead (value still dehydrated).
 */
final class SingleOptionField
{
    /**
     * @param  array<array-key, mixed>|Closure(): array<array-key, mixed>  $options
     * @param  Closure(Select): (Select|null)|null  $configure
     */
    public static function make(string $name, array|Closure $options, ?Closure $configure = null): Select|Hidden
    {
        $resolved = $options instanceof Closure ? $options() : $options;

        if (count($resolved) === 1) {
            $onlyKey = array_key_first($resolved);

            return Hidden::make($name)
                ->default($onlyKey)
                ->dehydrated();
        }

        $select = Select::make($name)->options($options);

        if ($configure !== null) {
            $configured = $configure($select);

            if ($configured instanceof Select) {
                return $configured;
            }
        }

        return $select;
    }
}

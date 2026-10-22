<?php

namespace App\Filament\Admin\Support;

use Closure;
use Filament\Tables\Columns\Column;
use Illuminate\Database\Eloquent\Model;

class DoubleClickCellEdit
{
    /**
     * Mark a table column cell as double-click editable.
     * The Livewire page/resource must use HasDoubleClickCellEdit and define the field key.
     */
    public static function enable(Column $column, string $field, ?Closure $when = null): Column
    {
        return $column->extraCellAttributes(
            function (Model $record) use ($field, $when): array {
                if ($when !== null && ! $when($record)) {
                    return [];
                }

                return [
                    'class' => 'ss-cell-edit',
                    'data-ss-cell-edit' => '1',
                    'data-ss-cell-field' => $field,
                    'data-ss-record-key' => (string) $record->getKey(),
                    'title' => 'Double-click to edit',
                ];
            },
            merge: true,
        );
    }
}

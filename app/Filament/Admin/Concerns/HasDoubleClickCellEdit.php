<?php

namespace App\Filament\Admin\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

trait HasDoubleClickCellEdit
{
    /**
     * Field key => config:
     * - label: string
     * - form: Component|Closure(): Component
     * - fill: Closure(Model): mixed
     * - save: Closure(Model, mixed): void
     * - can: ?Closure(Model): bool
     * - description: ?string|Closure(Model): ?string
     *
     * @return array<string, array{
     *     label: string,
     *     form: Component|\Closure(): Component,
     *     fill: \Closure(Model): mixed,
     *     save: \Closure(Model, mixed): void,
     *     can?: \Closure(Model): bool,
     *     description?: string|\Closure(Model): ?string
     * }>
     */
    abstract protected function doubleClickCellEditFields(): array;

    abstract protected function doubleClickCellEditRecord(string|int $key): ?Model;

    public function openDoubleClickCellEdit(string $recordKey, string $field): void
    {
        $fields = $this->doubleClickCellEditFields();

        if (! isset($fields[$field])) {
            Notification::make()
                ->title('This cell is not editable')
                ->warning()
                ->send();

            return;
        }

        $record = $this->doubleClickCellEditRecord($recordKey);

        if ($record === null) {
            Notification::make()
                ->title('Record not found')
                ->danger()
                ->send();

            return;
        }

        $can = $fields[$field]['can'] ?? null;
        if ($can !== null && ! $can($record)) {
            Notification::make()
                ->title('Cannot edit this field')
                ->body('This record does not support editing '.$fields[$field]['label'].'.')
                ->warning()
                ->send();

            return;
        }

        $this->mountAction('doubleClickCellEdit', [
            'recordKey' => (string) $recordKey,
            'field' => $field,
        ]);
    }

    public function doubleClickCellEditAction(): Action
    {
        return Action::make('doubleClickCellEdit')
            ->modalWidth('md')
            ->modalHeading(function (array $arguments): string {
                $field = (string) ($arguments['field'] ?? '');
                $label = $this->doubleClickCellEditFields()[$field]['label'] ?? 'Value';

                return 'Edit '.$label;
            })
            ->modalDescription(function (array $arguments): ?string {
                $field = (string) ($arguments['field'] ?? '');
                $config = $this->doubleClickCellEditFields()[$field] ?? null;
                if ($config === null) {
                    return null;
                }

                $record = $this->doubleClickCellEditRecord((string) ($arguments['recordKey'] ?? ''));
                if ($record === null) {
                    return null;
                }

                $description = $config['description'] ?? null;
                if ($description instanceof \Closure) {
                    return $description($record);
                }

                return is_string($description) ? $description : null;
            })
            ->fillForm(function (array $arguments): array {
                $field = (string) ($arguments['field'] ?? '');
                $config = $this->doubleClickCellEditFields()[$field] ?? null;
                $record = $this->doubleClickCellEditRecord((string) ($arguments['recordKey'] ?? ''));

                if ($config === null || $record === null) {
                    return ['value' => null];
                }

                return ['value' => ($config['fill'])($record)];
            })
            ->form(function (array $arguments): array {
                $field = (string) ($arguments['field'] ?? '');
                $config = $this->doubleClickCellEditFields()[$field] ?? null;

                if ($config === null) {
                    return [];
                }

                $form = $config['form'];
                $component = $form instanceof \Closure ? $form() : $form;

                return [$component];
            })
            ->action(function (array $data, array $arguments): void {
                $field = (string) ($arguments['field'] ?? '');
                $config = $this->doubleClickCellEditFields()[$field] ?? null;
                $record = $this->doubleClickCellEditRecord((string) ($arguments['recordKey'] ?? ''));

                if ($config === null || $record === null) {
                    Notification::make()
                        ->title('Could not save')
                        ->danger()
                        ->send();

                    return;
                }

                $can = $config['can'] ?? null;
                if ($can !== null && ! $can($record)) {
                    Notification::make()
                        ->title('Cannot edit this field')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    ($config['save'])($record->fresh() ?? $record, $data['value'] ?? null);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Validation failed')
                        ->body(collect($exception->errors())->flatten()->first() ?: $exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->title('Could not save')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                if (method_exists($this, 'resetTable')) {
                    $this->resetTable();
                }

                Notification::make()
                    ->title(($config['label'] ?? 'Value').' updated')
                    ->success()
                    ->send();
            });
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $attributes
     */
    protected function doubleClickValidate(mixed $value, array $rules, array $attributes = []): mixed
    {
        $validator = Validator::make(
            ['value' => $value],
            ['value' => $rules],
            [],
            $attributes === [] ? [] : ['value' => $attributes['value'] ?? 'value'],
        );

        return $validator->validate()['value'] ?? $value;
    }
}

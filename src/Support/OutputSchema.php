<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Support;

use AlfaSix\Alfakit\Forms\Components\Checkbox;
use AlfaSix\Alfakit\Forms\Components\CheckboxList;
use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Forms\Components\Radio;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TagsInput;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Forms\Components\ToggleButtons;
use AlfaSix\Alfakit\Schemas\Schema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Form fields as a structured-output schema, and the model's answer coerced back into
 * values those fields accept: unknown keys are dropped, options must be one of the field's
 * options, booleans, numbers and dates are parsed. Nothing the model invents reaches a field
 * that could not hold it.
 */
final class OutputSchema
{
    /**
     * The fields (by state path) of a list of components, layout components included.
     *
     * @param  array<int, \AlfaSix\Alfakit\Schemas\Components\Component>  $components
     * @return array<string, Field>
     */
    public static function fields(array $components): array
    {
        $fields = [];

        foreach (Schema::make()->components($components)->getFlatFields(withHidden: true) as $path => $field) {
            $fields[(string) $path] = $field;
        }

        return $fields;
    }

    /**
     * @param  array<string, Field>  $fields
     * @return array<string, Type>
     */
    public static function build(JsonSchema $schema, array $fields): array
    {
        $properties = [];

        foreach ($fields as $path => $field) {
            $properties[$path] = self::type($schema, $field)->description(self::describe($field))->required();
        }

        return $properties;
    }

    /**
     * A plain description of the fields, for prompts.
     *
     * @param  array<string, Field>  $fields
     * @return array<string, array<string, mixed>>
     */
    public static function describeAll(array $fields): array
    {
        $described = [];

        foreach ($fields as $path => $field) {
            $options = self::options($field);
            $described[$path] = array_filter([
                'label' => $field->getLabel(),
                'type' => self::kind($field),
                'options' => $options === null ? null : array_keys($options),
                'help' => method_exists($field, 'getHelperText') ? $field->getHelperText() : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $described;
    }

    /**
     * @param  array<string, Field>  $fields
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public static function coerce(array $fields, array $output): array
    {
        $values = [];

        foreach ($fields as $path => $field) {
            if (! array_key_exists($path, $output)) {
                continue;
            }

            $values[$path] = self::coerceValue($field, $output[$path]);
        }

        return $values;
    }

    public static function coerceValue(Field $field, mixed $value): mixed
    {
        $kind = self::kind($field);
        $options = self::options($field);

        if ($value === null) {
            return $kind === 'array' ? [] : null;
        }

        if ($kind === 'array') {
            $items = array_values(array_filter(is_array($value) ? $value : [$value], is_scalar(...)));
            $items = array_map(static fn (mixed $item): string => trim((string) $item), $items);

            if ($options !== null) {
                $allowed = array_map(strval(...), array_keys($options));
                $items = array_values(array_intersect($items, $allowed));
            }

            return array_values(array_unique(array_filter($items, static fn (string $item): bool => $item !== '')));
        }

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        return match ($kind) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'number' => is_numeric($value) ? $value + 0 : null,
            'date' => self::date((string) $value, 'Y-m-d'),
            'datetime' => self::date((string) $value, 'Y-m-d H:i:s'),
            'option' => $options !== null && in_array((string) $value, array_map(strval(...), array_keys($options)), true) ? (string) $value : null,
            default => trim((string) $value),
        };
    }

    public static function kind(Field $field): string
    {
        return match (true) {
            $field instanceof Toggle, $field instanceof Checkbox => 'boolean',
            $field instanceof CheckboxList, $field instanceof TagsInput => 'array',
            ($field instanceof Select || $field instanceof ToggleButtons) && $field->isMultiple() => 'array',
            $field instanceof Select, $field instanceof Radio, $field instanceof ToggleButtons => 'option',
            $field instanceof DateTimePicker => 'datetime',
            $field instanceof DatePicker => 'date',
            $field instanceof TextInput && $field->getInputType() === 'number' => 'number',
            default => 'string',
        };
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public static function options(Field $field): ?array
    {
        if (! method_exists($field, 'getOptions')) {
            return null;
        }

        try {
            $options = $field->getOptions();
        } catch (Throwable) {
            return null;
        }

        if (! is_array($options) || $options === []) {
            return null;
        }

        // Grouped options (`['Group' => ['a' => 'A']]`) are flattened.
        $flat = [];

        foreach ($options as $key => $label) {
            if (is_array($label)) {
                foreach ($label as $nestedKey => $nestedLabel) {
                    $flat[$nestedKey] = $nestedLabel;
                }
            } else {
                $flat[$key] = $label;
            }
        }

        return $flat;
    }

    private static function type(JsonSchema $schema, Field $field): Type
    {
        $options = self::options($field);
        $keys = $options === null ? null : array_map(strval(...), array_keys($options));

        return match (self::kind($field)) {
            'boolean' => $schema->boolean(),
            'number' => $schema->number(),
            'array' => $schema->array()->items($keys === null ? $schema->string() : $schema->string()->enum($keys)),
            'option' => $keys === null ? $schema->string() : $schema->string()->enum($keys),
            default => $schema->string(),
        };
    }

    private static function describe(Field $field): string
    {
        $parts = [(string) ($field->getLabel() ?? $field->getName())];
        $options = self::options($field);

        if ($options !== null) {
            $labels = [];

            foreach ($options as $key => $label) {
                $labels[] = $key.' = '.(is_scalar($label) ? $label : $key);
            }

            $parts[] = 'Options: '.implode('; ', $labels).'.';
        }

        $parts[] = match (self::kind($field)) {
            'date' => 'Format: YYYY-MM-DD.',
            'datetime' => 'Format: YYYY-MM-DD HH:MM:SS.',
            default => '',
        };

        $help = method_exists($field, 'getHelperText') ? $field->getHelperText() : null;

        if (is_string($help) && $help !== '') {
            $parts[] = $help;
        }

        $parts[] = 'Use an empty value when the source does not say.';

        return trim(implode(' ', array_filter($parts)));
    }

    private static function date(string $value, string $format): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, alfakit()->getTimezone())->format($format);
        } catch (Throwable) {
            return null;
        }
    }
}

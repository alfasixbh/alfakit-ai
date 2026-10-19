<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Support;

use AlfaSix\Alfakit\Infolists\Components\Entry;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Table;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JsonSerializable;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * What a prompt may know about a record: only the fields the resource shows (its infolist
 * entries, else its table columns, else its form fields), never `$hidden` attributes, and
 * with `AiPlugin::redact()` applied. Callers check `canView()` first.
 */
final class RecordData
{
    public const MAX_STRING_LENGTH = 4000;

    /**
     * @param  class-string<resource>|null  $resource
     * @return array<string, mixed>
     */
    public static function for(Model $record, ?string $resource = null, ?Redactor $redactor = null): array
    {
        $redactor ??= Redactor::make();
        $fields = [];

        foreach ($resource === null ? self::attributeNames($record) : self::fieldNames($resource, $record) as $name) {
            $value = self::value($record, $name);
            $fields[$name] = $redactor->isRedacted($name, $value, $record) ? Redactor::REDACTED : $value;
        }

        return array_filter([
            'resource' => $resource !== null ? $resource::getSlug() : null,
            'type' => $resource !== null ? $resource::getModelLabel() : class_basename($record),
            'key' => $record->getKey(),
            'title' => $resource !== null && $resource::hasRecordTitle() ? $resource::getRecordTitle($record) : null,
            'fields' => $fields,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The fields a user sees for records of `$resource`.
     *
     * @param  class-string<resource>  $resource
     * @return array<int, string>
     */
    public static function fieldNames(string $resource, ?Model $record = null): array
    {
        $model = $record ?? new ($resource::getModel());
        $names = self::infolistNames($resource, $model);

        if ($names === []) {
            $names = self::columnNames($resource, $model);
        }

        if ($names === []) {
            $names = self::formNames($resource, $model);
        }

        $hidden = $model->getHidden();
        $names = array_values(array_unique(array_filter(
            $names,
            static fn (string $name): bool => $name !== '' && ! in_array(Str::before($name, '.'), $hidden, true),
        )));

        return $names;
    }

    /**
     * @param  class-string<resource>  $resource
     * @return array<int, string>
     */
    private static function infolistNames(string $resource, Model $record): array
    {
        try {
            $schema = $resource::infolist(Schema::make()->record($record)->operation('view'));
            $names = [];

            foreach ($schema->getFlatComponents() as $component) {
                if ($component instanceof Entry && ! $component->isHiddenInTree()) {
                    $names[] = $component->getName();
                }
            }

            return $names;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  class-string<resource>  $resource
     * @return array<int, string>
     */
    private static function columnNames(string $resource, Model $record): array
    {
        try {
            $table = $resource::table(Table::make()->model($record::class));
            $names = [];

            foreach ($table->getColumns() as $column) {
                if (! $column->isHidden()) {
                    $names[] = $column->getName();
                }
            }

            return $names;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  class-string<resource>  $resource
     * @return array<int, string>
     */
    private static function formNames(string $resource, Model $record): array
    {
        try {
            $schema = $resource::form(Schema::make()->record($record)->operation('edit'));

            return array_map(static fn (int|string $path): string => (string) $path, array_keys($schema->getFlatFields()));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Without a resource: the record's own (non-hidden) attributes.
     *
     * @return array<int, string>
     */
    private static function attributeNames(Model $record): array
    {
        return array_keys($record->attributesToArray());
    }

    /**
     * A field's value, JSON-safe: relations (`author.name`, `tags.name`), enums, dates.
     */
    public static function value(Model $record, string $name): mixed
    {
        if (! str_contains($name, '.')) {
            return self::normalize($record->getAttribute($name));
        }

        $relation = Str::before($name, '.');
        $rest = Str::after($name, '.');

        if (! $record->isRelation($relation)) {
            return self::normalize(data_get($record, $name));
        }

        try {
            $related = $record->getRelationValue($relation);
        } catch (Throwable) {
            return null;
        }

        if ($related instanceof EloquentCollection) {
            return array_values(array_map(static fn (Model $item): mixed => self::value($item, $rest), $related->take(50)->all()));
        }

        return $related instanceof Model ? self::value($related, $rest) : null;
    }

    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => mb_strlen($value) > self::MAX_STRING_LENGTH ? mb_substr($value, 0, self::MAX_STRING_LENGTH).'…' : $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => Carbon::instance($value)->toIso8601String(),
            $value instanceof Model => $value->getKey(),
            $value instanceof Arrayable => array_map(self::normalize(...), $value->toArray()),
            $value instanceof JsonSerializable => self::normalize($value->jsonSerialize()),
            is_array($value) => array_map(self::normalize(...), $value),
            $value instanceof Stringable => (string) $value,
            default => null,
        };
    }
}

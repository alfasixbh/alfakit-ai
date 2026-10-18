<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Support;

use AlfaSix\AlfakitAi\AiPlugin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Decides which attributes never reach a prompt: the model's `$hidden` attributes, the
 * plugin's default sensitive names (passwords, tokens, secrets…), `AiPlugin::redact()`
 * patterns and `redactUsing()`.
 */
final class Redactor
{
    public const REDACTED = '[redacted]';

    public function __construct(private readonly ?AiPlugin $plugin) {}

    public static function make(?AiPlugin $plugin = null): self
    {
        return new self($plugin ?? AiPlugin::get());
    }

    public function isRedacted(string $attribute, mixed $value = null, ?Model $record = null): bool
    {
        $base = Str::afterLast($attribute, '.');
        $first = Str::before($attribute, '.');

        if ($record !== null && (in_array($attribute, $record->getHidden(), true) || in_array($first, $record->getHidden(), true))) {
            return true;
        }

        $candidates = array_values(array_unique(array_filter([
            $attribute,
            $base,
            $record !== null ? $record::class.'.'.$attribute : null,
            $record !== null ? $record->getTable().'.'.$attribute : null,
        ])));

        foreach ($this->plugin?->getRedactions() ?? AiPlugin::DEFAULT_REDACTIONS as $pattern) {
            foreach ($candidates as $candidate) {
                if (Str::is(Str::lower($pattern), Str::lower($candidate))) {
                    return true;
                }
            }
        }

        $callback = $this->plugin?->getRedactUsing();

        if ($callback !== null && $record !== null) {
            return (bool) $this->plugin->evaluate($callback, ['attribute' => $attribute, 'value' => $value, 'record' => $record], [Model::class => $record, $record::class => $record]);
        }

        return false;
    }

    /**
     * Replace redacted values of a flat attribute map.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function redact(array $values, ?Model $record = null): array
    {
        foreach ($values as $attribute => $value) {
            if ($this->isRedacted((string) $attribute, $value, $record)) {
                $values[$attribute] = self::REDACTED;
            }
        }

        return $values;
    }
}

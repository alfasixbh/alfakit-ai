<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Actions\Concerns;

use AlfaSix\AlfakitAi\Support\RecordData;
use AlfaSix\AlfakitAi\Support\Redactor;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * What a prebuilt action works on: `source()`, else the field's state (as a field action),
 * else the record's visible, redacted fields (only when the user may view it).
 */
trait HasSource
{
    protected ?Closure $sourceUsing = null;

    /**
     * `fn (Model $record): string => $record->body`, or `fn (?string $state) => $state`.
     */
    public function source(?Closure $callback): static
    {
        $this->sourceUsing = $callback;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    protected function getSource(array $inputs = []): mixed
    {
        if ($this->sourceUsing !== null) {
            return $this->evaluate($this->sourceUsing, ['data' => $inputs, 'inputs' => $inputs]);
        }

        $component = $this->getFieldComponent();

        if ($component !== null && method_exists($component, 'getState')) {
            return $component->getState();
        }

        $record = $this->getRecord();

        if (! $record instanceof Model) {
            return null;
        }

        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();
        $resource = $panel?->getModelResource($record);

        if ($resource !== null && ! $resource::canView($record)) {
            return null;
        }

        return RecordData::for($record, $resource, Redactor::make());
    }

    protected static function isBlankSource(mixed $source): bool
    {
        return $source === null || $source === '' || $source === [] || (is_string($source) && trim(strip_tags($source)) === '');
    }
}

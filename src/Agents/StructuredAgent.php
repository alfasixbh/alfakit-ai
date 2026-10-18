<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Agents;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * Structured answers of `AiAction` (output fields), `ExtractIntoFieldsAction` and
 * `ClassifyAction`: the provider is asked for JSON matching the schema.
 */
class StructuredAgent extends AlfakitAgent implements HasStructuredOutput
{
    /** @var (Closure(JsonSchema): array<string, \Illuminate\JsonSchema\Types\Type>)|null */
    protected ?Closure $schemaUsing = null;

    /**
     * @param  Closure(JsonSchema): array<string, \Illuminate\JsonSchema\Types\Type>  $schema
     */
    public function withSchema(Closure $schema): static
    {
        $this->schemaUsing = $schema;

        return $this;
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->schemaUsing === null ? [] : ($this->schemaUsing)($schema);
    }
}

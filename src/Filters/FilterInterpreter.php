<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Filters;

use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Tables\Filters\BaseFilter;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitAi\Support\OutputSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Throwable;

/**
 * Natural-language filters, safely: the model only sees the table's own filters, query
 * builder constraints and sortable columns, answers with structured output, and every part
 * of the answer is validated against those declarations (filter, field, value, constraint,
 * operator, column). One unknown or invalid part rejects the whole answer; nothing is ever
 * turned into SQL other than through the table's own filters.
 */
final class FilterInterpreter
{
    /** @var array<int, string> */
    private array $errors = [];

    public function __construct(private readonly Table $table) {}

    public static function for(Table $table): self
    {
        return new self($table);
    }

    /**
     * What the model may use, as sent in the prompt.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $filters = [];
        $queryBuilder = null;

        foreach ($this->table->getFilters() as $name => $filter) {
            if ($filter instanceof QueryBuilder) {
                $queryBuilder = [
                    'filter' => $name,
                    'constraints' => array_values(array_map(static fn ($constraint): array => $constraint->toSchema(), $filter->getConstraints())),
                ];

                continue;
            }

            $fields = $this->filterFields($filter);

            if ($fields === []) {
                continue;
            }

            $filters[] = ['name' => $name, 'label' => $filter->getLabel(), 'fields' => OutputSchema::describeAll($fields)];
        }

        $sortable = [];

        foreach ($this->table->getColumns() as $column) {
            if ($column->isSortable() && ! $column->isHidden()) {
                $sortable[] = ['name' => $column->getName(), 'label' => $column->getLabel()];
            }
        }

        return array_filter([
            'searchable' => $this->table->isSearchable(),
            'sortable_columns' => $sortable,
            'filters' => $filters,
            'query_builder' => $queryBuilder,
            'today' => now(alfakit()->getTimezone())->toDateString(),
            'timezone' => alfakit()->getTimezone(),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public static function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Free-text search, or "" for none.')->required(),
            'sort_column' => $schema->string()->description('A sortable column name, or "" to keep the order.')->required(),
            'sort_direction' => $schema->string()->enum(['asc', 'desc', ''])->required(),
            'filters' => $schema->array()->items($schema->object([
                'filter' => $schema->string()->required(),
                'field' => $schema->string()->required(),
                'values' => $schema->array()->items($schema->string())->required(),
            ])->withoutAdditionalProperties())->required(),
            'combinator' => $schema->string()->enum(['and', 'or'])->required(),
            'rules' => $schema->array()->items($schema->object([
                'constraint' => $schema->string()->required(),
                'operator' => $schema->string()->required(),
                'values' => $schema->array()->items($schema->string())->required(),
            ])->withoutAdditionalProperties())->required(),
            'explanation' => $schema->string()->description('One short sentence describing what is shown, in the user\'s language.')->required(),
            'unsupported' => $schema->boolean()->description('True when the request cannot be expressed with these filters.')->required(),
        ];
    }

    /**
     * Validate the model's answer. Returns the table state (`search`, `sort`, `direction`,
     * `filters`) or null when anything is invalid (see `getErrors()`).
     *
     * @param  array<string, mixed>  $answer
     * @return array{search: ?string, sort: ?string, direction: ?string, filters: array<string, array<string, mixed>>}|null
     */
    public function interpret(array $answer): ?array
    {
        $this->errors = [];
        $state = ['search' => null, 'sort' => null, 'direction' => null, 'filters' => []];

        $search = is_string($answer['search'] ?? null) ? trim($answer['search']) : '';

        if ($search !== '') {
            if (! $this->table->isSearchable()) {
                $this->errors[] = (string) __('alfakit-ai::ai.filters.errors.not_searchable');
            } else {
                $state['search'] = mb_substr($search, 0, 255);
            }
        }

        $sort = is_string($answer['sort_column'] ?? null) ? trim($answer['sort_column']) : '';

        if ($sort !== '') {
            $column = $this->table->getColumn($sort);

            if ($column === null || ! $column->isSortable() || $column->isHidden()) {
                $this->errors[] = (string) __('alfakit-ai::ai.filters.errors.unknown_column', ['name' => $sort]);
            } else {
                $state['sort'] = $sort;
                $state['direction'] = ($answer['sort_direction'] ?? '') === 'desc' ? 'desc' : 'asc';
            }
        }

        foreach ($this->listOf($answer['filters'] ?? []) as $item) {
            $this->interpretFilter($item, $state);
        }

        $rules = $this->listOf($answer['rules'] ?? []);

        if ($rules !== []) {
            $this->interpretRules($rules, ($answer['combinator'] ?? 'and') === 'or' ? 'or' : 'and', $state);
        }

        return $this->errors === [] ? $state : null;
    }

    /**
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return array_values(array_unique($this->errors));
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{search: ?string, sort: ?string, direction: ?string, filters: array<string, array<string, mixed>>}  $state
     */
    private function interpretFilter(array $item, array &$state): void
    {
        $name = is_string($item['filter'] ?? null) ? $item['filter'] : '';
        $filter = $name === '' ? null : $this->table->getFilter($name);

        if ($filter === null || $filter instanceof QueryBuilder) {
            $this->errors[] = (string) __('alfakit-ai::ai.filters.errors.unknown_filter', ['name' => $name]);

            return;
        }

        $fieldName = is_string($item['field'] ?? null) ? $item['field'] : '';
        $field = $this->filterFields($filter)[$fieldName] ?? null;

        if ($field === null) {
            $this->errors[] = (string) __('alfakit-ai::ai.filters.errors.unknown_field', ['name' => $name.'.'.$fieldName]);

            return;
        }

        $values = array_values(array_filter(is_array($item['values'] ?? null) ? $item['values'] : [], is_scalar(...)));

        if ($values === []) {
            return;
        }

        $value = OutputSchema::coerceValue($field, OutputSchema::kind($field) === 'array' ? $values : $values[0]);

        if ($value === null || $value === [] || (is_array($value) && count($value) !== count(array_unique(array_map(strval(...), $values))))) {
            $this->errors[] = (string) __('alfakit-ai::ai.filters.errors.invalid_value', ['name' => $filter->getLabel()]);

            return;
        }

        $state['filters'][$name][$fieldName] = $value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array{search: ?string, sort: ?string, direction: ?string, filters: array<string, array<string, mixed>>}  $state
     */
    private function interpretRules(array $rules, string $combinator, array &$state): void
    {
        $builder = null;

        foreach ($this->table->getFilters() as $filter) {
            if ($filter instanceof QueryBuilder) {
                $builder = $filter;

                break;
            }
        }

        if ($builder === null) {
            $this->errors[] = (string) __('alfakit-ai::ai.filters.errors.no_query_builder');

            return;
        }

        $constraints = $builder->getConstraints();
        $resolved = [];

        foreach ($rules as $rule) {
            $name = is_string($rule['constraint'] ?? null) ? $rule['constraint'] : '';
            $operator = is_string($rule['operator'] ?? null) ? $rule['operator'] : '';
            $constraint = $constraints[$name] ?? null;

            if ($constraint === null) {
                $this->errors[] = (string) __('alfakit-ai::ai.filters.errors.unknown_constraint', ['name' => $name]);

                continue;
            }

            if (! $constraint->hasOperator($operator)) {
                $this->errors[] = (string) __('alfakit-ai::ai.filters.errors.unknown_operator', ['name' => $operator, 'constraint' => $constraint->getLabel()]);

                continue;
            }

            $values = array_values(array_filter(is_array($rule['values'] ?? null) ? $rule['values'] : [], is_scalar(...)));
            $value = count($values) > 1 ? $values : ($values[0] ?? null);

            try {
                $valid = $constraint->resolveRule($operator, $value);
            } catch (Throwable) {
                $valid = null;
            }

            if ($valid === null) {
                $this->errors[] = (string) __('alfakit-ai::ai.filters.errors.invalid_value', ['name' => $constraint->getLabel()]);

                continue;
            }

            $resolved[] = ['type' => 'constraint', 'constraint' => $constraint->getName(), 'operator' => $valid['operator'], 'value' => $valid['value']];
        }

        if ($resolved !== []) {
            $state['filters'][$builder->getName()] = ['query' => ['combinator' => $combinator, 'rules' => $resolved]];
        }
    }

    /**
     * The fields of a filter's form the model may set (fields whose values can be checked).
     *
     * @return array<string, Field>
     */
    private function filterFields(BaseFilter $filter): array
    {
        try {
            $fields = OutputSchema::fields($filter->getForm());
        } catch (Throwable) {
            return [];
        }

        return array_filter($fields, static function (Field $field): bool {
            $kind = OutputSchema::kind($field);

            // Options must be known to be validated.
            return ! in_array($kind, ['option', 'array'], true) || OutputSchema::options($field) !== null || $kind === 'array' && ! method_exists($field, 'getOptions');
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listOf(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, is_array(...)));
    }
}

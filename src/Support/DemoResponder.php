<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Support;

use AlfaSix\AlfakitAi\Actions\AiAssistAction;
use AlfaSix\AlfakitAi\Agents\AlfakitAgent;
use AlfaSix\AlfakitAi\Agents\AssistAgent;
use AlfaSix\AlfakitAi\Agents\FilterAgent;
use AlfaSix\AlfakitAi\Agents\StructuredAgent;
use AlfaSix\AlfakitAi\Copilot\CopilotAgent;
use AlfaSix\AlfakitAi\Copilot\Tools\CopilotTool;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use Closure;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * The provider behind `AiPlugin::demoResponses()`: answers the plugin's agents locally and
 * deterministically, without a network call, with the same laravel/ai response objects a
 * provider returns (provider and model `demo`, no tokens). Features run their usual code
 * path; only the answer comes from here.
 *
 * It recognises the plugin's own prompts (the `<data>` blocks of `PromptData` and the
 * first line of each task): summaries keep the first sentences or fields, translations
 * return the text with a "[Sample translation]" marker, rewrites use simple templates,
 * extraction and classification match patterns and option labels, filters understand a few
 * phrases, and the copilot runs its real tools for "search …", "open …" and "run … on …".
 * Bind your own subclass in the container to change the samples.
 */
class DemoResponder
{
    public const PROVIDER = 'demo';

    public const MODEL = 'demo';

    public function respond(AiFeature $feature, AlfakitAgent $agent, string $prompt): AgentResponse
    {
        $blocks = self::blocks($prompt);

        return match (true) {
            $agent instanceof CopilotAgent => $this->text($this->copilot($agent, $prompt)),
            $agent instanceof FilterAgent => $this->structured($this->filters($blocks)),
            $agent instanceof AssistAgent => $this->text($this->assist($prompt, $blocks)),
            $agent instanceof StructuredAgent => $this->structured($this->structuredAnswer($agent, $prompt, $blocks)),
            default => $this->text($this->textAnswer($prompt, $blocks)),
        };
    }

    /**
     * Whether a response is a sample answer.
     */
    public static function isDemo(AgentResponse $response): bool
    {
        return $response->meta->provider === self::PROVIDER;
    }

    protected function text(string $text): AgentResponse
    {
        return new AgentResponse('demo', $text, new TextUsage, new Meta(self::PROVIDER, self::MODEL));
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    protected function structured(array $structured): StructuredAgentResponse
    {
        return new StructuredAgentResponse('demo', $structured, (string) json_encode($structured), new TextUsage, new Meta(self::PROVIDER, self::MODEL));
    }

    // ---------------------------------------------------------------- Text (AiAction, Summarize, Translate)

    /**
     * @param  array<string, mixed>  $blocks
     */
    protected function textAnswer(string $prompt, array $blocks): string
    {
        $source = $blocks['source'] ?? null;

        if (preg_match('/^Summarise the content below in (.+?), as (prose|a bullet list)\./', $prompt, $match) === 1) {
            $count = str_contains($match[1], 'few') ? 5 : (str_contains($match[1], 'paragraph') ? 3 : 2);

            return $this->summarize($source, $count, $match[2] === 'a bullet list');
        }

        if (preg_match('/^Translate the content below into (.+?) \(locale [^)]+\)/', $prompt, $match) === 1) {
            return $this->markTranslation($match[1], $source);
        }

        $request = self::firstSentence(self::plain($prompt));
        $answer = (string) __('alfakit-ai::ai.demo.answer', ['request' => Str::limit($request, 160)]);
        $extract = $this->summarize($source, 2, false);

        return $extract === '' ? $answer : $answer."\n\n".$extract;
    }

    protected function summarize(mixed $source, int $count, bool $bullets): string
    {
        $items = is_array($source) ? self::fieldLines($source) : self::sentences(self::toText($source));
        $items = array_slice($items, 0, is_array($source) ? $count * 2 : $count);

        if ($items === []) {
            return '';
        }

        if ($bullets) {
            return implode("\n", array_map(static fn (string $item): string => '- '.$item, $items));
        }

        return is_array($source) ? implode('; ', $items).'.' : implode(' ', $items);
    }

    protected function markTranslation(string $language, mixed $source): string
    {
        $marker = (string) __('alfakit-ai::ai.demo.translation', ['language' => $language]);

        if (is_string($source) && $source !== strip_tags($source)) {
            return '<p>'.e($marker).'</p>'.$source;
        }

        $text = is_array($source) ? implode("\n", self::fieldLines($source)) : self::toText($source, keepLines: true);

        return trim($marker."\n\n".$text);
    }

    // ---------------------------------------------------------------- Writing assistant

    /**
     * @param  array<string, mixed>  $blocks
     */
    protected function assist(string $prompt, array $blocks): string
    {
        $first = trim((string) strtok($prompt, "\n"));
        $task = 'continue';

        foreach (AiAssistAction::REQUESTS as $name => $request) {
            if (str_starts_with($first, $request)) {
                $task = $name;

                break;
            }
        }

        $rich = str_contains($prompt, 'The text is HTML');
        $source = $blocks['text'] ?? $blocks['current_text'] ?? '';
        $text = self::toText(is_string($source) ? $source : '', keepLines: ! $rich);
        $instructions = is_string($blocks['instructions'] ?? null) ? trim($blocks['instructions']) : '';

        $answer = match ($task) {
            'generate' => (string) __('alfakit-ai::ai.demo.generated', ['instructions' => Str::limit($instructions, 200)]),
            'shorter' => $this->shorten($text),
            'longer' => rtrim($text).' '.__('alfakit-ai::ai.demo.longer'),
            'friendlier' => __('alfakit-ai::ai.demo.friendlier_open').' '.self::grammar($text).' '.__('alfakit-ai::ai.demo.friendlier_close'),
            'formal' => __('alfakit-ai::ai.demo.formal_open').' '.self::grammar($text).' '.__('alfakit-ai::ai.demo.formal_close'),
            'grammar' => self::grammar($text),
            'translate' => (string) __('alfakit-ai::ai.demo.translation', ['language' => preg_match('/into (.+?) \(locale/', $first, $match) === 1 ? $match[1] : 'English'])."\n\n".$text,
            default => (string) __('alfakit-ai::ai.demo.continue'),
        };

        if (! $rich) {
            return $answer;
        }

        $paragraphs = array_filter(array_map(trim(...), preg_split('/\n{2,}/', $answer) ?: []));

        return implode('', array_map(static fn (string $paragraph): string => '<p>'.e($paragraph).'</p>', $paragraphs));
    }

    protected function shorten(string $text): string
    {
        $sentences = self::sentences($text);

        if (count($sentences) > 1) {
            return implode(' ', array_slice($sentences, 0, (int) ceil(count($sentences) / 2)));
        }

        $words = preg_split('/\s+/u', trim($text)) ?: [];

        if (count($words) <= 4) {
            return trim($text);
        }

        return rtrim(implode(' ', array_slice($words, 0, max(3, (int) ceil(count($words) * 0.6)))), ',;:').'…';
    }

    /**
     * Whitespace, spaces around punctuation and capitals at the start of sentences.
     */
    public static function grammar(string $text): string
    {
        $lines = [];

        foreach (preg_split('/\n{2,}/', trim($text)) ?: [] as $paragraph) {
            $paragraph = (string) preg_replace('/[ \t]+/u', ' ', trim($paragraph));
            $paragraph = (string) preg_replace('/\s+([,.;:!?])/u', '$1', $paragraph);
            $paragraph = (string) preg_replace('/([,;:!?]|\.(?!\.))(?=\p{L})/u', '$1 ', $paragraph);
            $paragraph = (string) preg_replace('/\bi\b/u', 'I', $paragraph);
            $paragraph = (string) preg_replace_callback('/(^|[.!?]\s+)(\p{Ll})/u', static fn (array $match): string => $match[1].mb_strtoupper($match[2]), $paragraph);

            if ($paragraph !== '' && preg_match('/[\p{L}\p{N}]$/u', $paragraph) === 1) {
                $paragraph .= '.';
            }

            $lines[] = $paragraph;
        }

        return implode("\n\n", array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    // ---------------------------------------------------------------- Structured answers (schema(), Extract, Classify)

    /**
     * @param  array<string, mixed>  $blocks
     * @return array<string, mixed>
     */
    protected function structuredAnswer(StructuredAgent $agent, string $prompt, array $blocks): array
    {
        $properties = [];

        foreach ($agent->schema(new JsonSchemaTypeFactory) as $name => $type) {
            $properties[(string) $name] = $type->toArray();
        }

        if (is_array($blocks['options'] ?? null) && array_key_exists('value', $properties)) {
            return $this->classify($blocks['options'], self::toText($blocks['source'] ?? ''));
        }

        if (is_array($blocks['fields'] ?? null)) {
            return $this->extract($blocks['fields'], self::toText($blocks['source'] ?? '', keepLines: true));
        }

        $text = trim(self::plain($prompt)."\n".self::toText($blocks['source'] ?? '', keepLines: true));
        $request = Str::limit(self::firstSentence(self::plain($prompt)), 160);
        $answer = [];

        foreach ($properties as $name => $property) {
            $label = Str::headline($name);
            $type = is_string($property['type'] ?? null) ? $property['type'] : 'string';
            $enum = is_array($property['enum'] ?? null) ? array_values(array_filter($property['enum'], static fn (mixed $value): bool => $value !== '')) : [];

            $answer[$name] = match (true) {
                $enum !== [] => $enum[0],
                $type === 'boolean' => false,
                $type === 'array' => [],
                $type === 'number', $type === 'integer' => self::extractValue($label, $name, 'number', null, $text),
                default => self::extractValue($label, $name, 'string', null, $text)
                    ?? (preg_match('/body|message|content|description|notes?|text|reply|summary|answer/i', $name) === 1
                        ? (string) __('alfakit-ai::ai.demo.field_text', ['request' => $request])
                        : (string) __('alfakit-ai::ai.demo.field', ['label' => mb_strtolower($label)])),
            };
        }

        return $answer;
    }

    /**
     * The option whose label (or key) words appear most in the text, else the first.
     *
     * @param  array<int|string, mixed>  $options
     * @return array{value: string, reason: string}
     */
    protected function classify(array $options, string $text): array
    {
        $best = null;
        $bestScore = 0;
        $bestWords = [];

        foreach ($options as $key => $label) {
            $words = array_unique([...self::words(is_scalar($label) ? (string) $label : ''), ...self::words((string) $key)]);
            $score = 0;
            $found = [];

            foreach ($words as $word) {
                $count = preg_match_all('/\b'.preg_quote($word, '/').'\b/iu', $text);

                if ($count > 0) {
                    $score += $count;
                    $found[] = $word;
                }
            }

            $best ??= (string) $key;

            if ($score > $bestScore) {
                [$best, $bestScore, $bestWords] = [(string) $key, $score, $found];
            }
        }

        return [
            'value' => (string) $best,
            'reason' => $bestScore > 0
                ? (string) __('alfakit-ai::ai.demo.classify_match', ['words' => implode('”, “', $bestWords)])
                : (string) __('alfakit-ai::ai.demo.classify_default'),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $fields  path => {label, type, options}
     * @return array<string, mixed>
     */
    protected function extract(array $fields, string $text): array
    {
        $values = [];

        if (trim($text) === '') {
            return $values;
        }

        foreach ($fields as $path => $description) {
            $description = is_array($description) ? $description : [];
            $label = is_string($description['label'] ?? null) ? $description['label'] : Str::headline((string) $path);
            $type = is_string($description['type'] ?? null) ? $description['type'] : 'string';
            $options = is_array($description['options'] ?? null) ? $description['options'] : null;

            $value = self::extractValue($label, (string) $path, $type, $options, $text);

            if ($value !== null) {
                $values[(string) $path] = $type === 'array' ? [$value] : $value;
            }
        }

        return $values;
    }

    /**
     * A value for one field from free text: a "Label: value" line, else a pattern the label
     * suggests (email, phone, date, amount, company or person name, an option).
     *
     * @param  array<int|string, mixed>|null  $options
     */
    public static function extractValue(string $label, string $name, string $type, ?array $options, string $text): ?string
    {
        foreach (array_unique([$label, Str::headline($name), $name]) as $caption) {
            if ($caption !== '' && preg_match('/^[ \t]*'.preg_quote($caption, '/').'[ \t]*[:=][ \t]*(.+)$/imu', $text, $match) === 1) {
                return trim($match[1]);
            }
        }

        $hint = mb_strtolower($label.' '.$name);

        if ($options !== null) {
            foreach ($options as $key => $option) {
                $key = is_int($key) && is_scalar($option) ? (string) $option : (string) $key;

                foreach (array_unique([$key, Str::headline($key)]) as $word) {
                    if (mb_strlen($word) >= 3 && preg_match('/\b'.preg_quote($word, '/').'\b/iu', $text) === 1) {
                        return $key;
                    }
                }
            }

            return null;
        }

        if (str_contains($hint, 'mail')) {
            return preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $match) === 1 ? $match[0] : null;
        }

        if (preg_match('/phone|mobile|tel|fax|whatsapp/', $hint) === 1) {
            return preg_match('/\+?\d[\d\s().-]{6,}\d/', $text, $match) === 1 ? trim($match[0]) : null;
        }

        if (in_array($type, ['date', 'datetime'], true) || preg_match('/date|deadline|\bdue\b|birthday|\bday\b/', $hint) === 1) {
            return self::findDate($text);
        }

        if ($type === 'number' || preg_match('/total|amount|price|cost|sum|fee|balance|budget|salary|quantity|qty/', $hint) === 1) {
            return self::findAmount($text, $type === 'number' && preg_match('/total|amount|price|cost|sum|fee|balance|budget|salary/', $hint) !== 1);
        }

        if (preg_match('/company|organi[sz]ation|business|vendor|supplier|customer|client|account|employer/', $hint) === 1) {
            return self::findCompany($text) ?? self::findPerson($text);
        }

        if (preg_match('/name|contact|person|sender|author/', $hint) === 1) {
            $person = self::findPerson($text);

            if ($person !== null && str_contains($hint, 'first')) {
                return explode(' ', $person)[0];
            }

            if ($person !== null && str_contains($hint, 'last')) {
                $parts = explode(' ', $person);

                return count($parts) > 1 ? end($parts) : null;
            }

            return $person;
        }

        return null;
    }

    protected static function findDate(string $text): ?string
    {
        $months = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

        foreach ([
            '/\b\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?\b/',
            '/\b(?:'.$months.')\.? \d{1,2}(?:st|nd|rd|th)?,? \d{4}\b/i',
            '/\b\d{1,2}(?:st|nd|rd|th)? (?:'.$months.')\.?,? \d{4}\b/i',
            '/\b\d{1,2}[\/.]\d{1,2}[\/.]\d{4}\b/',
        ] as $pattern) {
            if (preg_match($pattern, $text, $match) === 1) {
                try {
                    return Carbon::parse(str_replace(['st ', 'nd ', 'rd ', 'th '], ' ', $match[0]))->format(str_contains($match[0], ':') ? 'Y-m-d H:i:s' : 'Y-m-d');
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    protected static function findAmount(string $text, bool $anyNumber): ?string
    {
        $patterns = [
            '/(?:total|amount|price|cost|sum|due|fee|balance|budget|invoice)\D{0,20}?([$€£¥]?\s?\d[\d,]*(?:\.\d+)?)/iu',
            '/[$€£¥]\s?(\d[\d,]*(?:\.\d+)?)/u',
            '/(\d[\d,]*(?:\.\d+)?)\s?(?:USD|EUR|GBP|AED|SAR|dollars?|euros?)\b/iu',
        ];

        if ($anyNumber) {
            $patterns[] = '/(?<![\w.-])(\d+(?:\.\d+)?)(?![\w-])/u';
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) === 1) {
                $number = (string) preg_replace('/[^\d.]/', '', $match[1]);

                if (is_numeric($number)) {
                    return $number;
                }
            }
        }

        return null;
    }

    protected static function findCompany(string $text): ?string
    {
        $name = '[A-Z][\p{L}\d&\'.-]*(?:\s+[A-Z][\p{L}\d&\'.-]*){0,3}';

        if (preg_match('/\b('.$name.'\s+(?:Inc|Ltd|LLC|LLP|Corp|Corporation|GmbH|Co|SA|BV|PLC|AG)\b\.?)/u', $text, $match) === 1) {
            return trim($match[1]);
        }

        if (preg_match('/\b(?:from|at|with|of)\s+('.$name.')/u', $text, $match) === 1) {
            return rtrim(trim($match[1]), '.');
        }

        return null;
    }

    protected static function findPerson(string $text): ?string
    {
        $name = '([A-Z]\p{Ll}+(?:\s+[A-Z]\p{Ll}+)?)';

        foreach ([
            '/(?i:my name is|i am|i\'m|this is)\s+'.$name.'/u',
            '/(?i:regards|thanks|thank you|cheers|best|sincerely),?\s*\n+\s*'.$name.'/u',
            '/\b'.$name.'\s+(?:from|at|here)\b/u',
        ] as $pattern) {
            if (preg_match($pattern, $text, $match) === 1) {
                return trim($match[1]);
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- Table filters

    /**
     * @param  array<string, mixed>  $blocks
     * @return array<string, mixed>
     */
    protected function filters(array $blocks): array
    {
        $table = is_array($blocks['table'] ?? null) ? $blocks['table'] : [];
        $request = is_string($blocks['request'] ?? null) ? trim($blocks['request']) : '';
        $lower = mb_strtolower($request);
        $today = Carbon::parse(is_string($table['today'] ?? null) ? $table['today'] : now()->toDateString());
        $answer = ['search' => '', 'sort_column' => '', 'sort_direction' => '', 'filters' => [], 'combinator' => 'and', 'rules' => [], 'explanation' => '', 'unsupported' => false];
        $parts = [];

        /** @var array<int, array<string, mixed>> $filters */
        $filters = array_values(array_filter(is_array($table['filters'] ?? null) ? $table['filters'] : [], is_array(...)));
        /** @var array<int, array<string, mixed>> $constraints */
        $constraints = array_values(array_filter(is_array($table['query_builder']['constraints'] ?? null) ? $table['query_builder']['constraints'] : [], is_array(...)));
        $usedColumn = null;

        // Option filters whose option is named: "paid orders".
        foreach ($filters as $filter) {
            foreach (is_array($filter['fields'] ?? null) ? $filter['fields'] : [] as $field => $description) {
                if (! is_array($description) || ! in_array($description['type'] ?? null, ['option', 'array'], true) || ! is_array($description['options'] ?? null)) {
                    continue;
                }

                foreach ($description['options'] as $option) {
                    $option = (string) $option;

                    if (mb_strlen($option) < 3 || in_array(mb_strtolower($option), ['true', 'false', 'yes', 'no'], true)) {
                        continue;
                    }

                    if (preg_match('/\b'.preg_quote(mb_strtolower(Str::headline($option)), '/').'\b|\b'.preg_quote(mb_strtolower($option), '/').'\b/u', $lower) === 1) {
                        $answer['filters'][] = ['filter' => (string) ($filter['name'] ?? ''), 'field' => (string) $field, 'values' => [$option]];
                        $parts[] = ($filter['label'] ?? $filter['name'] ?? '').': '.Str::headline($option);

                        continue 3;
                    }
                }
            }
        }

        // Numbers with a comparison: "over 100", "at most 50".
        $numberConstraint = self::pickConstraint($constraints, 'number', $lower);
        $operators = ['at least' => 'gte', 'at most' => 'lte', 'more than' => 'gt', 'greater than' => 'gt', 'over' => 'gt', 'above' => 'gt', 'less than' => 'lt', 'fewer than' => 'lt', 'under' => 'lt', 'below' => 'lt', '>=' => 'gte', '<=' => 'lte', '>' => 'gt', '<' => 'lt'];

        if ($numberConstraint !== null && preg_match('/('.implode('|', array_map(static fn (string $word): string => preg_quote($word, '/'), array_keys($operators))).')\s*[$€£]?\s*(\d+(?:\.\d+)?)/u', $lower, $match) === 1) {
            $operator = $operators[$match[1]];

            if (self::hasOperator($numberConstraint, $operator)) {
                $answer['rules'][] = ['constraint' => (string) $numberConstraint['name'], 'operator' => $operator, 'values' => [$match[2]]];
                $parts[] = ($numberConstraint['label'] ?? $numberConstraint['name']).' '.['gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤'][$operator].' '.$match[2];
                $usedColumn = (string) $numberConstraint['name'];
            }
        }

        // Date phrases: "today", "last week", "this month".
        $range = self::dateRange($lower, $today);

        if ($range !== null) {
            [$phrase, $from, $until, $unit] = $range;
            $dateConstraint = self::pickConstraint($constraints, 'date', $lower);

            if ($dateConstraint !== null) {
                [$operator, $value] = match ($unit) {
                    'day' => ['is_date', $from->toDateString()],
                    'month' => ['is_month', $from->format('Y-m')],
                    'year' => ['is_year', $from->format('Y')],
                    default => ['is_after', $from->copy()->subDay()->toDateString()],
                };

                if (self::hasOperator($dateConstraint, $operator)) {
                    $answer['rules'][] = ['constraint' => (string) $dateConstraint['name'], 'operator' => $operator, 'values' => [$value]];
                    $parts[] = ($dateConstraint['label'] ?? $dateConstraint['name']).': '.$phrase;
                }
            } elseif (($dates = self::dateFilter($filters)) !== null) {
                [$filter, $fromField, $untilField] = $dates;
                $answer['filters'][] = ['filter' => $filter['name'], 'field' => $fromField, 'values' => [$from->toDateString()]];

                if ($untilField !== null) {
                    $answer['filters'][] = ['filter' => $filter['name'], 'field' => $untilField, 'values' => [$until->toDateString()]];
                }

                $parts[] = ($filter['label'] ?? $filter['name']).': '.$phrase;
            }
        }

        // Sorting: "biggest first", "newest first".
        $sortable = is_array($table['sortable_columns'] ?? null) ? array_values(array_filter($table['sortable_columns'], is_array(...))) : [];
        $direction = match (true) {
            preg_match('/\b(highest|biggest|largest|most expensive|descending|desc)\b/u', $lower) === 1 => 'desc',
            preg_match('/\b(lowest|smallest|cheapest|ascending|asc)\b/u', $lower) === 1 => 'asc',
            preg_match('/\b(newest|latest|most recent)\b/u', $lower) === 1 => 'newest',
            preg_match('/\b(oldest)\b/u', $lower) === 1 => 'oldest',
            default => null,
        };

        if ($direction !== null && $sortable !== []) {
            $column = null;

            foreach ($sortable as $candidate) {
                $name = (string) ($candidate['name'] ?? '');
                $label = mb_strtolower((string) ($candidate['label'] ?? $name));

                if (in_array($direction, ['newest', 'oldest'], true) ? preg_match('/(_at|date|created|updated)$/', $name) === 1 : (str_contains($lower, $label) || $name === $usedColumn)) {
                    $column = $candidate;

                    break;
                }
            }

            if ($column !== null) {
                $answer['sort_column'] = (string) $column['name'];
                $answer['sort_direction'] = in_array($direction, ['desc', 'newest'], true) ? 'desc' : 'asc';
                $parts[] = ($column['label'] ?? $column['name']).' '.($answer['sort_direction'] === 'desc' ? '↓' : '↑');
            }
        }

        // Quoted words, or "named …", search the table.
        if (($table['searchable'] ?? false) === true && preg_match('/["“”\']([^"“”\']{2,})["“”\']|\b(?:named|called|matching|containing|search for)\s+(\S+)/u', $request, $match, PREG_UNMATCHED_AS_NULL) === 1) {
            $answer['search'] = trim((string) ($match[1] ?? $match[2]));
            $parts[] = '“'.$answer['search'].'”';
        }

        if ($parts === []) {
            $answer['unsupported'] = true;
            $answer['explanation'] = __('alfakit-ai::ai.demo.label').'. '.__('alfakit-ai::ai.demo.filters_help', ['example' => self::filterExample($filters, $constraints)]);

            return $answer;
        }

        $answer['explanation'] = __('alfakit-ai::ai.demo.label').'. '.__('alfakit-ai::ai.demo.filters_applied', ['filters' => implode(' · ', $parts)]);

        return $answer;
    }

    /**
     * @param  array<int, array<string, mixed>>  $constraints
     * @return array<string, mixed>|null
     */
    protected static function pickConstraint(array $constraints, string $type, string $request): ?array
    {
        $ofType = array_values(array_filter($constraints, static fn (array $constraint): bool => ($constraint['type'] ?? null) === $type));

        foreach ($ofType as $constraint) {
            $label = mb_strtolower((string) ($constraint['label'] ?? ''));

            if (($label !== '' && str_contains($request, $label)) || str_contains($request, mb_strtolower((string) ($constraint['name'] ?? '')))) {
                return $constraint;
            }
        }

        return $ofType[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $constraint
     */
    protected static function hasOperator(array $constraint, string $operator): bool
    {
        foreach (is_array($constraint['operators'] ?? null) ? $constraint['operators'] : [] as $candidate) {
            if (is_array($candidate) && ($candidate['name'] ?? null) === $operator) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: Carbon, 2: Carbon, 3: string}|null [phrase, from, until, unit]
     */
    protected static function dateRange(string $request, Carbon $today): ?array
    {
        $today = $today->copy()->startOfDay();

        return match (true) {
            str_contains($request, 'yesterday') => ['yesterday', $today->copy()->subDay(), $today->copy()->subDay(), 'day'],
            str_contains($request, 'today') => ['today', $today, $today, 'day'],
            str_contains($request, 'this week') => ['this week', $today->copy()->startOfWeek(), $today, 'range'],
            preg_match('/last week|past week|last 7 days|past 7 days/u', $request) === 1 => ['last week', $today->copy()->subDays(7), $today, 'range'],
            str_contains($request, 'last month') => ['last month', $today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth(), 'month'],
            str_contains($request, 'this month') => ['this month', $today->copy()->startOfMonth(), $today, 'month'],
            str_contains($request, 'last year') => ['last year', $today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear(), 'year'],
            str_contains($request, 'this year') => ['this year', $today->copy()->startOfYear(), $today, 'year'],
            default => null,
        };
    }

    /**
     * A filter with "from" (and "until") date fields.
     *
     * @param  array<int, array<string, mixed>>  $filters
     * @return array{0: array<string, mixed>, 1: string, 2: ?string}|null
     */
    protected static function dateFilter(array $filters): ?array
    {
        foreach ($filters as $filter) {
            $from = null;
            $until = null;

            foreach (is_array($filter['fields'] ?? null) ? $filter['fields'] : [] as $field => $description) {
                if (! is_array($description) || ! in_array($description['type'] ?? null, ['date', 'datetime'], true)) {
                    continue;
                }

                if (preg_match('/until|to$|end|before|max/i', (string) $field) === 1) {
                    $until ??= (string) $field;
                } else {
                    $from ??= (string) $field;
                }
            }

            if ($from !== null) {
                return [$filter, $from, $until];
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $filters
     * @param  array<int, array<string, mixed>>  $constraints
     */
    protected static function filterExample(array $filters, array $constraints): string
    {
        $words = [];

        foreach ($filters as $filter) {
            foreach (is_array($filter['fields'] ?? null) ? $filter['fields'] : [] as $description) {
                $option = is_array($description) && is_array($description['options'] ?? null) ? ($description['options'][0] ?? null) : null;

                if (is_scalar($option) && ! in_array((string) $option, ['true', 'false', '1', '0'], true)) {
                    $words[] = mb_strtolower(Str::headline((string) $option));

                    break 2;
                }
            }
        }

        $number = self::pickConstraint($constraints, 'number', '');

        if ($number !== null) {
            $words[] = mb_strtolower((string) ($number['label'] ?? $number['name'])).' over 100';
        }

        $words[] = 'this month';

        return implode(' ', $words);
    }

    // ---------------------------------------------------------------- Copilot

    protected function copilot(CopilotAgent $agent, string $prompt): string
    {
        $question = trim(self::plain($prompt));
        $gateway = PanelGateway::current();
        $tools = [];

        foreach ($agent->tools() as $tool) {
            if ($tool instanceof CopilotTool) {
                $tools[$tool->name()] = $tool;
            }
        }

        $call = static function (string $name, array $arguments) use ($tools): array {
            $tool = $tools[$name] ?? null;

            if ($tool === null) {
                return [];
            }

            $result = json_decode((string) $tool->handle(new Request($arguments)), true);

            return is_array($result) ? $result : [];
        };

        $resources = [];

        foreach ($gateway?->catalogue() ?? [] as $entry) {
            $resources[(string) $entry['resource']] = $entry;
        }

        // "run mark paid on SO-1"
        if (preg_match('/^(?:run|do|use|apply)\s+(.+?)\s+on\s+(.+)$/iu', $question, $match) === 1) {
            return $this->copilotRun($call, $resources, trim($match[1]), trim($match[2], " \t\n.?!"));
        }

        // "search acme orders", "find SO-1"
        if (preg_match('/^(?:search|find|look\s*up|lookup)\s+(?:for\s+)?(.+)$/iu', $question, $match) === 1) {
            return $this->copilotSearch($call, $resources, trim($match[1], " \t\n.?!"));
        }

        // "open orders", "go to new order", "show SO-1"
        if (preg_match('/^(?:open|go\s+to|goto|navigate\s+to|take\s+me\s+to|show(?:\s+me)?|list|create|new|add)\s*(.*)$/iu', $question, $match) === 1) {
            $verb = mb_strtolower(strtok($question, ' ') ?: '');

            return $this->copilotOpen($call, $resources, trim($match[1], " \t\n.?!"), in_array($verb, ['create', 'new', 'add'], true), $verb === 'list');
        }

        return $this->copilotHelp($resources);
    }

    /**
     * @param  array<string, array<string, mixed>>  $resources
     * @return array{0: ?string, 1: string} [resource slug, the rest of the text]
     */
    protected function findResource(array $resources, string $text): array
    {
        foreach ($resources as $slug => $resource) {
            foreach (array_unique(array_filter([$resource['plural'] ?? null, $resource['label'] ?? null, $slug, str_replace('-', ' ', $slug)])) as $name) {
                $pattern = '/\b'.preg_quote(mb_strtolower((string) $name), '/').'\b/u';

                if (preg_match($pattern, mb_strtolower($text)) === 1) {
                    return [$slug, trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\b'.preg_quote((string) $name, '/').'\b/iu', '', $text, 1)))];
                }
            }
        }

        return [null, $text];
    }

    /**
     * @param  Closure(string, array<string, mixed>): array<string, mixed>  $call
     * @param  array<string, array<string, mixed>>  $resources
     */
    protected function copilotSearch(Closure $call, array $resources, string $text): string
    {
        [$slug, $query] = $this->findResource($resources, $text);
        $query = trim((string) preg_replace('/^(?:the|all|my|for)\s+/i', '', $query));
        $result = $call('search_records', ['query' => $query, 'resource' => $slug ?? '']);
        $results = is_array($result['results'] ?? null) ? $result['results'] : [];

        if ($results === []) {
            return (string) __('alfakit-ai::ai.demo.copilot.none', ['query' => $query !== '' ? $query : $text]);
        }

        if (count($results) === 1) {
            $only = $results[0];
            $call('navigate', ['resource' => (string) ($only['resource'] ?? ''), 'page' => 'view', 'key' => (string) ($only['key'] ?? '')]);
        }

        $heading = $query === ''
            ? (string) __('alfakit-ai::ai.demo.copilot.latest', ['label' => $slug !== null ? ($resources[$slug]['plural'] ?? $slug) : ''])
            : trans_choice('alfakit-ai::ai.demo.copilot.found', count($results), ['count' => count($results), 'query' => $query]);

        return $heading."\n".$this->resultLines($resources, $results);
    }

    /**
     * @param  array<string, array<string, mixed>>  $resources
     * @param  array<int, mixed>  $results
     */
    protected function resultLines(array $resources, array $results): string
    {
        $lines = [];

        foreach (array_slice($results, 0, 10) as $result) {
            if (! is_array($result)) {
                continue;
            }

            $label = Str::ucfirst((string) ($resources[(string) ($result['resource'] ?? '')]['label'] ?? $result['resource'] ?? ''));
            $lines[] = '- '.($result['title'] ?? '#'.($result['key'] ?? '')).' ('.$label.')';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  Closure(string, array<string, mixed>): array<string, mixed>  $call
     * @param  array<string, array<string, mixed>>  $resources
     */
    protected function copilotOpen(Closure $call, array $resources, string $text, bool $create, bool $list): string
    {
        if (preg_match('/^(?:a\s+|an\s+)?(?:new|create)\s+(.+)$/iu', $text, $match) === 1) {
            [$create, $text] = [true, $match[1]];
        }

        [$slug, $rest] = $this->findResource($resources, $text);

        if ($slug !== null && ($create || trim((string) preg_replace('/\b(the|all|my|list|page|of)\b/i', '', $rest)) === '')) {
            if ($list) {
                return $this->copilotSearch($call, $resources, $text);
            }

            $result = $call('navigate', ['resource' => $slug, 'page' => $create ? 'create' : 'index', 'key' => '']);

            return ($result['shown'] ?? false) === true
                ? (string) __('alfakit-ai::ai.demo.copilot.opened')
                : (string) __('alfakit-ai::ai.demo.copilot.not_found', ['query' => $text]);
        }

        $found = $call('search_records', ['query' => $rest, 'resource' => $slug ?? '']);
        $first = is_array($found['results'][0] ?? null) ? $found['results'][0] : null;

        if ($first === null) {
            return (string) __('alfakit-ai::ai.demo.copilot.not_found', ['query' => $text]);
        }

        $result = $call('navigate', ['resource' => (string) ($first['resource'] ?? ''), 'page' => 'view', 'key' => (string) ($first['key'] ?? '')]);

        if (($result['shown'] ?? false) !== true) {
            $result = $call('navigate', ['resource' => (string) ($first['resource'] ?? ''), 'page' => 'edit', 'key' => (string) ($first['key'] ?? '')]);
        }

        return ($result['shown'] ?? false) === true
            ? (string) __('alfakit-ai::ai.demo.copilot.opened')
            : (string) __('alfakit-ai::ai.demo.copilot.not_found', ['query' => $text]);
    }

    /**
     * @param  Closure(string, array<string, mixed>): array<string, mixed>  $call
     * @param  array<string, array<string, mixed>>  $resources
     */
    protected function copilotRun(Closure $call, array $resources, string $action, string $target): string
    {
        [$slug, $query] = $this->findResource($resources, $target);
        $found = $call('search_records', ['query' => $query, 'resource' => $slug ?? '']);
        $first = is_array($found['results'][0] ?? null) ? $found['results'][0] : null;

        if ($first === null) {
            return (string) __('alfakit-ai::ai.demo.copilot.not_found', ['query' => $target]);
        }

        $record = $call('read_record', ['resource' => (string) ($first['resource'] ?? ''), 'key' => (string) ($first['key'] ?? '')]);
        $actions = is_array($record['actions'] ?? null) ? $record['actions'] : [];
        $wanted = mb_strtolower($action);
        $match = null;

        foreach ($actions as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $label = mb_strtolower((string) ($candidate['label'] ?? ''));
            $name = mb_strtolower((string) ($candidate['name'] ?? ''));

            if ($wanted === $label || $wanted === $name || mb_strtolower(Str::headline($name)) === $wanted || ($label !== '' && str_contains($label, $wanted))) {
                $match = $candidate;

                break;
            }
        }

        $title = (string) ($first['title'] ?? '#'.($first['key'] ?? ''));

        if ($match === null) {
            return (string) __('alfakit-ai::ai.demo.copilot.no_action', [
                'action' => $action,
                'record' => $title,
                'actions' => implode(', ', array_filter(array_map(static fn (mixed $candidate): string => is_array($candidate) ? (string) ($candidate['label'] ?? $candidate['name'] ?? '') : '', $actions))) ?: '—',
            ]);
        }

        $proposal = $call('propose_action', [
            'resource' => (string) ($first['resource'] ?? ''),
            'key' => (string) ($first['key'] ?? ''),
            'action' => (string) ($match['name'] ?? ''),
            'inputs_json' => '{}',
            'reason' => (string) __('alfakit-ai::ai.demo.copilot.reason', ['action' => (string) ($match['label'] ?? $match['name'] ?? ''), 'record' => $title]),
        ]);

        return ($proposal['status'] ?? null) === 'awaiting_user_confirmation'
            ? (string) __('alfakit-ai::ai.demo.copilot.proposed')
            : (string) __('alfakit-ai::ai.demo.copilot.no_action', ['action' => $action, 'record' => $title, 'actions' => '—']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $resources
     */
    protected function copilotHelp(array $resources): string
    {
        $first = $resources === [] ? null : reset($resources);
        $plural = mb_strtolower((string) ($first['plural'] ?? __('alfakit-ai::ai.demo.copilot.records')));

        return (string) __('alfakit-ai::ai.demo.copilot.help', ['plural' => $plural]);
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * The `<data>` blocks of a prompt (see `PromptData::wrap()`), decoded.
     *
     * @return array<string, mixed>
     */
    public static function blocks(string $prompt): array
    {
        preg_match_all('/<data name="([A-Za-z0-9_-]+)">\n(.*?)\n<\/data>/s', $prompt, $matches, PREG_SET_ORDER);
        $blocks = [];

        foreach ($matches as $match) {
            $blocks[$match[1]] = json_decode($match[2], true);
        }

        return $blocks;
    }

    /**
     * The prompt without its `<data>` blocks.
     */
    public static function plain(string $prompt): string
    {
        return trim((string) preg_replace('/<data name="[A-Za-z0-9_-]+">\n.*?\n<\/data>/s', '', $prompt));
    }

    protected static function toText(mixed $source, bool $keepLines = false): string
    {
        if (is_array($source)) {
            return implode($keepLines ? "\n" : ' ', self::fieldLines($source));
        }

        if (! is_scalar($source)) {
            return '';
        }

        $text = (string) $source;

        if ($text !== strip_tags($text)) {
            $text = (string) preg_replace('/<\/(p|div|h[1-6]|li|blockquote)>|<br\s*\/?>/i', "\n\n", $text);
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        }

        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);

        return $keepLines ? trim((string) preg_replace('/\n{3,}/', "\n\n", $text)) : trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * "Label: value" for the filled, unredacted values of a record (`RecordData`) or array.
     *
     * @param  array<int|string, mixed>  $source
     * @return array<int, string>
     */
    protected static function fieldLines(array $source): array
    {
        $fields = is_array($source['fields'] ?? null) ? $source['fields'] : $source;
        $lines = [];

        foreach ($fields as $name => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, is_scalar(...)));
            }

            if (! is_scalar($value) || $value === '' || $value === Redactor::REDACTED || is_bool($value)) {
                continue;
            }

            $lines[] = (is_string($name) ? Str::headline($name).': ' : '').Str::limit(self::toText((string) $value), 200);
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    protected static function sentences(string $text): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), preg_split('/(?<=[.!?])\s+(?=\S)/u', $text) ?: []), static fn (string $sentence): bool => $sentence !== ''));
    }

    protected static function firstSentence(string $text): string
    {
        return self::sentences($text)[0] ?? '';
    }

    /**
     * @return array<int, string>
     */
    protected static function words(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(Str::headline($text))) ?: [];

        return array_values(array_filter($words, static fn (string $word): bool => mb_strlen($word) >= 3));
    }
}

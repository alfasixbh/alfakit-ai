<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Actions;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\AlfakitAi\Actions\Concerns\HasSource;
use AlfaSix\AlfakitAi\Support\PromptData;
use Closure;
use Locale;

/**
 * Translate a field's text (or `source()`) into a language the user picks; changing the
 * language translates again.
 *
 * ```php
 * Textarea::make('description')->hintAction(TranslateAction::make()->fillField())
 * TranslateAction::make()->source(fn (Product $record) => $record->description)->locales(['fr', 'de', 'ar'])
 * ```
 */
class TranslateAction extends AiAction
{
    use HasSource;

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $locales = null;

    protected string|Closure|null $defaultLocale = null;

    public static function make(string $name = 'translate'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-ai::ai.prebuilt.translate'));
        $this->modalHeading(__('alfakit-ai::ai.prebuilt.translate'));
        $this->icon('languages');
        $this->language(null);
        $this->inputs(fn (): array => [
            Select::make('locale')
                ->label(__('alfakit-ai::ai.translate.to'))
                ->options($this->getLocaleOptions())
                ->default($this->getDefaultLocale())
                ->required()
                ->native(false)
                ->searchable(),
        ]);
        $this->prompt(fn (array $data): string => $this->buildPrompt($data));
    }

    /**
     * The target languages (locales); the panel's locales, or common languages, by default.
     *
     * @param  array<int, string>|Closure|null  $locales
     */
    public function locales(array|Closure|null $locales): static
    {
        $this->locales = $locales;

        return $this;
    }

    public function defaultLocale(string|Closure|null $locale): static
    {
        $this->defaultLocale = $locale;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getLocaleOptions(): array
    {
        $locales = $this->evaluate($this->locales);

        if (! is_array($locales) || $locales === []) {
            $locales = array_values(array_map(
                static fn (\AlfaSix\Alfakit\Localization\Locale $locale): string => $locale->getCode(),
                alfakit()->getCurrentOrDefaultPanel()->getLocales(),
            ));
        }

        if (count($locales) < 2) {
            $locales = ['en', 'ar', 'de', 'es', 'fr', 'it', 'ja', 'nl', 'pt_BR', 'ru', 'tr', 'zh_CN'];
        }

        $options = [];

        foreach ($locales as $locale) {
            $options[$locale] = self::languageName($locale);
        }

        return $options;
    }

    public function getDefaultLocale(): ?string
    {
        $locale = $this->evaluate($this->defaultLocale);

        if (is_string($locale) && $locale !== '') {
            return $locale;
        }

        $current = app()->getLocale();

        foreach (array_keys($this->getLocaleOptions()) as $option) {
            if ($option !== $current) {
                return $option;
            }
        }

        return null;
    }

    public static function languageName(string $locale): string
    {
        if (class_exists(Locale::class)) {
            $name = Locale::getDisplayName($locale, app()->getLocale());

            if (is_string($name) && $name !== '' && $name !== $locale) {
                return mb_convert_case(mb_substr($name, 0, 1), MB_CASE_TITLE).mb_substr($name, 1);
            }
        }

        return $locale;
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    protected function buildPrompt(array $inputs): string
    {
        $locale = $inputs['locale'] ?? null;
        $source = $this->getSource($inputs);

        if (! is_string($locale) || ! array_key_exists($locale, $this->getLocaleOptions()) || self::isBlankSource($source)) {
            return '';
        }

        $language = self::languageName($locale);
        $html = is_string($source) && $source !== strip_tags($source);

        return "Translate the content below into {$language} (locale {$locale}). Keep the meaning, tone, names, numbers and"
            .($html ? ' the HTML markup exactly; translate only the text.' : ' line breaks. Answer with the translation only.')
            ."\n\n".PromptData::wrap($source, 'source');
    }
}

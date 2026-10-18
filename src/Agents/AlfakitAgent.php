<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * The base of the plugin's agents. Each feature has its own agent class, so tests fake one
 * feature at a time (`TextAgent::fake(['A summary.'])`) and providers, models and limits are
 * set per call by `AiRunner` from the plugin's configuration.
 */
abstract class AlfakitAgent implements Agent
{
    use Promptable;

    protected string $instructions = '';

    /** @var string|array<int|string, string>|null */
    protected string|array|null $providerName = null;

    protected ?string $modelName = null;

    protected ?float $temperatureValue = null;

    protected ?int $maxTokensValue = null;

    protected int $timeoutSeconds = 60;

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function withInstructions(string $instructions): static
    {
        $this->instructions = $instructions;

        return $this;
    }

    /**
     * @param  string|array<int|string, string>|null  $provider
     */
    public function using(string|array|null $provider, ?string $model): static
    {
        $this->providerName = $provider;
        $this->modelName = $model;

        return $this;
    }

    public function withTemperature(?float $temperature): static
    {
        $this->temperatureValue = $temperature;

        return $this;
    }

    public function withMaxTokens(?int $tokens): static
    {
        $this->maxTokensValue = $tokens;

        return $this;
    }

    public function withTimeout(int $seconds): static
    {
        $this->timeoutSeconds = max(1, $seconds);

        return $this;
    }

    /**
     * Read by laravel/ai; null falls back to `ai.default` (what `alfakit:ai-setup` writes).
     *
     * @return string|array<int|string, string>|null
     */
    public function provider(): string|array|null
    {
        return $this->providerName;
    }

    public function model(): ?string
    {
        return $this->modelName;
    }

    public function temperature(): ?float
    {
        return $this->temperatureValue;
    }

    public function maxTokens(): ?int
    {
        return $this->maxTokensValue;
    }

    public function timeout(): int
    {
        return $this->timeoutSeconds;
    }
}

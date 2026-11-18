<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Caller;

use IndianOpinion\LlmJson\Attempt;
use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\CostSource;
use IndianOpinion\LlmJson\Exception\AuthenticationException;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException;
use IndianOpinion\LlmJson\Exception\LlmJsonException;
use IndianOpinion\LlmJson\Guard\GuardProfile;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\Outcome;
use IndianOpinion\LlmJson\PriceTable;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Store\WordPressTransientStore;
use IndianOpinion\LlmJson\Transport\Transport;
use IndianOpinion\LlmJson\Transport\WordPressTransport;
use IndianOpinion\WcProductCopy\Exception\GenerationException;
use IndianOpinion\WcProductCopy\Exception\NotConfiguredException;
use IndianOpinion\WcProductCopy\Settings;

/**
 * Reference adapter over llm-json-php.
 *
 * llm-json-php 0.1 accepts string message content only, so this adapter
 * is text-only: supportsImages() is false and the draft record says the
 * image was not sent (ruling on open question Q1).
 */
final class LlmJsonCaller implements ModelCaller
{
    /** Prefix of the library's per-site transients (cooldowns, holds). Removed on uninstall. */
    public const STORE_PREFIX = 'product_copy_llm';

    public function __construct(
        private readonly Client $client,
        private readonly string $provider,
        private readonly string $model
    ) {
    }

    /**
     * Build the client from the settings and key. The filter
     * `product_copy_client` may supply a configured client instead.
     */
    public static function fromSettings(Settings $settings, string $apiKey, ?Transport $transport = null): self
    {
        $provider = self::buildProvider($settings, $apiKey);
        $route = Route::to($provider, $settings->model());
        $client = Client::create(Chain::of($route), $transport ?? new WordPressTransport())
            ->withStore(new WordPressTransientStore(null, null, null, false))
            ->withOptions(Options::defaults()->withStoreKeyPrefix(self::STORE_PREFIX));
        $prices = $settings->priceTable();
        if ($prices !== []) {
            $table = PriceTable::fromArray([]);
            foreach ($prices as $model => [$in, $out]) {
                $table = $table->with($model, $in, $out);
            }
            $client = $client->withPriceTable($table);
        }
        /** @var mixed $filtered */
        $filtered = apply_filters('product_copy_client', $client, $settings);
        if ($filtered instanceof Client) {
            $client = $filtered;
        }

        return new self($client, $settings->provider(), $settings->model());
    }

    public static function isConfigured(Settings $settings, string $apiKey): bool
    {
        if ($settings->model() === '') {
            return false;
        }
        if ($settings->provider() === 'generic') {
            return $settings->baseUrl() !== '';
        }

        return $apiKey !== '';
    }

    public function supportsImages(): bool
    {
        return false;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function call(ModelRequest $request): ModelResponse
    {
        $requested = $request->requestedFields;
        try {
            $schema = Schema::fromArray('product_copy', $request->schema);
            $profile = GuardProfile::forSchema($schema)->withCustomRule('requested-fields', static function (array $data) use ($requested): array {
                $violations = [];
                foreach ($requested as $field) {
                    $key = $field === 'short' ? 'short_html' : 'description_html';
                    if (!is_string($data[$key] ?? null) || trim(strip_tags($data[$key])) === '') {
                        $violations[] = ['/' . $key, 'The requested field is empty.'];
                    }
                }

                return $violations;
            });
            $llm = Request::for($request->purpose, $schema)
                ->withSystem($request->system)
                ->withInput($request->user)
                ->withTokenCap($request->tokenCap)
                ->withGuardProfile($profile);
            $result = $this->client->complete($llm);
        } catch (ConfigurationException $e) {
            throw new GenerationException('The model route is not set up correctly: '
                . $e->getMessage(), GenerationException::PERMANENT, 0, null, $e);
        } catch (ChainExhaustedException $e) {
            $billed = self::billed($e->attempts());
            $cost = self::cost($e->attempts());
            $kind = self::kind($e);
            throw new GenerationException('The model call failed after retries.', $kind, $billed, $cost, $e);
        } catch (LlmJsonException $e) {
            throw new GenerationException('The model call failed.', GenerationException::RETRYABLE, 1, null, $e);
        }

        $usage = $result->totalUsage();

        return new ModelResponse(
            $result->data(),
            $result->reportedModel() ?? $result->model(),
            $usage->promptTokens,
            $usage->completionTokens,
            $usage->cost,
            self::costSource($usage->costSource, $usage->cost),
            max(1, self::billed($result->attempts()))
        );
    }

    private static function buildProvider(Settings $settings, string $apiKey): Provider
    {
        $home = function_exists('home_url') ? (string) home_url('/') : null;
        $name = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : null;
        switch ($settings->provider()) {
            case 'openai':
                if ($apiKey === '') {
                    throw new NotConfiguredException('No API key is set.', 'not_configured');
                }
                return Provider::openAi($apiKey);
            case 'generic':
                if ($settings->baseUrl() === '') {
                    throw new NotConfiguredException('No base URL is set for the generic provider.', 'not_configured');
                }
                return Provider::generic('generic', $settings->baseUrl(), $apiKey, $apiKey === '');
            default:
                if ($apiKey === '') {
                    throw new NotConfiguredException('No API key is set.', 'not_configured');
                }
                return Provider::openRouter($apiKey, $home !== '' ? $home : null, $name !== '' ? $name : null);
        }
    }

    /**
     * Attempts that reached the provider (an HTTP status came back).
     *
     * @param list<Attempt> $attempts
     */
    private static function billed(array $attempts): int
    {
        $n = 0;
        foreach ($attempts as $attempt) {
            if ($attempt->httpStatus !== null) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param list<Attempt> $attempts
     */
    private static function cost(array $attempts): ?float
    {
        $total = null;
        foreach ($attempts as $attempt) {
            if ($attempt->usage->cost !== null) {
                $total = ($total ?? 0.0) + $attempt->usage->cost;
            }
        }

        return $total;
    }

    private static function kind(ChainExhaustedException $e): string
    {
        if ($e instanceof CreditExhaustedException) {
            return GenerationException::ACCOUNT;
        }
        if ($e->lastError() instanceof AuthenticationException) {
            return GenerationException::ACCOUNT;
        }
        $last = null;
        foreach ($e->attempts() as $attempt) {
            $last = $attempt;
        }
        if ($last instanceof Attempt) {
            if (in_array($last->outcome, [Outcome::AuthenticationFailed, Outcome::CreditExhausted], true)) {
                return GenerationException::ACCOUNT;
            }
            if (!$last->outcome->isRetryable()) {
                return GenerationException::PERMANENT;
            }
        }

        return GenerationException::RETRYABLE;
    }

    private static function costSource(CostSource $source, ?float $cost): string
    {
        if ($cost === null) {
            return 'unknown';
        }

        return match ($source) {
            CostSource::Reported => 'reported',
            CostSource::PriceTable => 'price_table',
            default => 'partial',
        };
    }
}

<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Prompt;

use IndianOpinion\WcProductCopy\Facts\FactList;
use IndianOpinion\WcProductCopy\Fields;
use IndianOpinion\WcProductCopy\Log;
use IndianOpinion\WcProductCopy\Settings;

/**
 * Cases 37 and 38. The prompt comes from prompt-library when it is active
 * (template `product-copy-generate`, consumer `wc-product-copy`), otherwise
 * from the built-in text below. The filter `product_copy_prompt` can
 * replace either.
 */
final class PromptBuilder
{
    public const VERSION = 'builtin-1';
    public const TEMPLATE_SLUG = 'product-copy-generate';
    public const CONSUMER = 'wc-product-copy';

    public function __construct(private readonly Log $log)
    {
    }

    /**
     * The built-in system template. Placeholders in braces are filled from
     * the variables of render().
     */
    public static function builtinSystem(): string
    {
        return implode("\n", [
            'You write product copy for an online shop.',
            'Write in {language}.',
            'Use only the facts listed by the user and what is plainly visible in the product image, if one is attached.',
            'Never invent specifications, materials, certifications, compatibility, origin, warranties or awards.',
            'Never mention price, discounts, stock, delivery or returns.',
            'Never mention or compare with competitors or other shops.',
            'Do not include links, email addresses or phone numbers.',
            'Write only the fields requested, within the lengths given:',
            '{field_rules}',
            'Use simple HTML only: paragraphs, lists, bold and italic. No headings, no links, no Markdown.',
            'Return the ids of the facts you used (for example F1, F3) in used_facts.',
            'When the facts were too thin to write well, say so in a short note for the editor (at most 300 characters); otherwise leave the note empty.',
            '{tone_rule}',
        ]);
    }

    /**
     * @param list<string> $fields
     */
    public function build(FactList $facts, array $fields, Settings $settings, bool $withImage, int $productId = 0): Prompt
    {
        $vars = self::variables($facts, $fields, $settings, $withImage);
        $system = null;
        $user = null;
        $version = self::VERSION;
        $source = 'builtin';
        $templateHash = hash('sha256', self::builtinSystem());

        if (function_exists('prompt_library_render')) {
            try {
                /** @var mixed $rendered */
                $rendered = prompt_library_render(self::TEMPLATE_SLUG, $vars, ['consumer' => self::CONSUMER]);
                if (is_array($rendered) && is_string($rendered['system'] ?? null) && is_string($rendered['user'] ?? null) && trim($rendered['user']) !== '') {
                    $system = $rendered['system'];
                    $user = $rendered['user'];
                    $version = is_scalar($rendered['version'] ?? null) ? 'library-' . (string) $rendered['version'] : 'library';
                    $templateHash = is_string($rendered['hash'] ?? null) ? $rendered['hash'] : hash('sha256', $system);
                    $source = 'prompt-library';
                } elseif (is_string($rendered) && trim($rendered) !== '') {
                    $system = $rendered;
                    $user = self::builtinUser($vars);
                    $version = 'library';
                    $templateHash = hash('sha256', $rendered);
                    $source = 'prompt-library';
                } else {
                    $this->log->add('prompt_fallback', $productId, 'library_empty');
                }
            } catch (\Throwable $e) {
                $this->log->add('prompt_fallback', $productId, get_class($e));
            }
        }
        if ($system === null || $user === null) {
            $system = self::fill(self::builtinSystem(), $vars);
            $user = self::builtinUser($vars);
        }

        /** @var mixed $filtered */
        $filtered = apply_filters('product_copy_prompt', ['system' => $system, 'user' => $user], [
            'product_id' => $productId,
            'fields' => $fields,
            'facts' => $facts->toArray(),
            'title' => $facts->title,
            'image' => $withImage,
            'source' => $source,
            'variables' => $vars,
        ]);
        if (is_array($filtered) && is_string($filtered['system'] ?? null) && is_string($filtered['user'] ?? null)) {
            if ($filtered['system'] !== $system || $filtered['user'] !== $user) {
                $source .= '+filter';
                $version .= '+filter';
                $templateHash = hash('sha256', $templateHash . "\0" . $filtered['system']);
            }
            $system = $filtered['system'];
            $user = $filtered['user'];
        }

        return new Prompt($system, $user, $version, $templateHash, $source);
    }

    /**
     * Variables offered to a prompt-library template and used by the
     * built-in one.
     *
     * @param list<string> $fields
     *
     * @return array<string, string>
     */
    public static function variables(FactList $facts, array $fields, Settings $settings, bool $withImage): array
    {
        $rules = [];
        foreach ($fields as $field) {
            $limits = $settings->lengths($field);
            $key = $field === Fields::SHORT ? 'short_html' : 'description_html';
            $what = $field === Fields::SHORT ? 'a short description shown near the buy button' : 'the main product description';
            $rules[] = sprintf('- %s: %s, %d to %d %s.', $key, $what, $limits['min'], $limits['max'], $limits['unit']);
        }
        foreach (array_diff(Fields::ALL, $fields) as $field) {
            $rules[] = sprintf('- %s: not requested, return null.', $field === Fields::SHORT ? 'short_html' : 'description_html');
        }
        $lines = [];
        foreach ($facts->facts as $fact) {
            $lines[] = $fact->id . '. ' . $fact->label . ': ' . $fact->value;
        }
        $tone = $settings->tone();

        return [
            'language' => $settings->language(),
            'field_rules' => implode("\n", $rules),
            'fields' => implode(', ', $fields),
            'tone' => $tone,
            'tone_rule' => $tone === '' ? '' : 'Tone: ' . $tone,
            'title' => $facts->title,
            'facts' => implode("\n", $lines),
            'image_line' => $withImage ? 'One product photo is attached.' : 'No image is attached.',
        ];
    }

    /**
     * @param array<string, string> $vars
     */
    private static function builtinUser(array $vars): string
    {
        return implode("\n", [
            'Product title: ' . $vars['title'],
            '',
            'Facts:',
            $vars['facts'] === '' ? '(none)' : $vars['facts'],
            '',
            'Fields to write: ' . $vars['fields'],
            $vars['image_line'],
        ]);
    }

    /**
     * @param array<string, string> $vars
     */
    private static function fill(string $template, array $vars): string
    {
        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{' . $key . '}'] = $value;
        }

        return rtrim(strtr($template, $replace));
    }

    /**
     * The JSON schema of case 39. Requested fields must be non-empty
     * strings, so a missing one fails the attempt (case 40); others may be
     * null.
     *
     * @param list<string> $fields
     *
     * @return array<string, mixed>
     */
    public static function schema(array $fields): array
    {
        $field = static fn (bool $requested): array => $requested
            ? ['type' => 'string', 'minLength' => 1]
            : ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'properties' => [
                'description_html' => $field(in_array(Fields::DESCRIPTION, $fields, true)),
                'short_html' => $field(in_array(Fields::SHORT, $fields, true)),
                'used_facts' => ['type' => 'array', 'items' => ['type' => 'string']],
                'note' => ['type' => 'string'],
            ],
            'required' => ['description_html', 'short_html', 'used_facts', 'note'],
            'additionalProperties' => false,
        ];
    }
}

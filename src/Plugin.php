<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

use IndianOpinion\WcProductCopy\Admin\Admin;
use IndianOpinion\WcProductCopy\Cli\Command;
use IndianOpinion\WcProductCopy\Facts\FactBuilder;
use IndianOpinion\WcProductCopy\Image\ImagePreparer;
use IndianOpinion\WcProductCopy\Prompt\PromptBuilder;
use IndianOpinion\WcProductCopy\Queue\ActionSchedulerRunner;
use IndianOpinion\WcProductCopy\Queue\GenQueueRunner;
use IndianOpinion\WcProductCopy\Queue\Runner;
use IndianOpinion\WcProductCopy\Review\Acceptor;

/**
 * Wiring. Builds the services once per request and hooks them in, or stays
 * inert with an admin notice when WooCommerce or a model caller is missing
 * (cases 60 and 61).
 */
final class Plugin
{
    public const VERSION = '0.1.0';

    private static ?self $instance = null;

    public readonly SettingsRepository $settings;
    public readonly Credentials $credentials;
    public readonly KillSwitch $switch;
    public readonly Usage $usage;
    public readonly Log $log;
    public readonly DraftStore $drafts;
    public readonly FactBuilder $facts;
    public readonly PromptBuilder $prompts;
    public readonly CallerFactory $callers;
    public readonly Planner $planner;
    public readonly Lock $lock;
    public readonly SpendMeter $meter;
    public readonly Generator $generator;
    public readonly Runner $runner;
    public readonly Queuer $queuer;
    public readonly Acceptor $acceptor;
    public readonly Housekeeping $housekeeping;

    /** Why the plugin is inert, or null when it is running. */
    private static ?string $inert = null;

    /** The class whose presence means WooCommerce is active. Tests change it. */
    public static string $wooCommerceClass = 'WooCommerce';

    private function __construct()
    {
        $this->settings = new SettingsRepository();
        $this->credentials = new Credentials();
        $this->switch = new KillSwitch();
        $this->usage = new Usage();
        $this->log = new Log();
        $this->drafts = new DraftStore();
        $this->facts = new FactBuilder($this->log);
        $this->prompts = new PromptBuilder($this->log);
        $this->callers = new CallerFactory($this->settings, $this->credentials);
        $this->planner = new Planner($this->settings, $this->drafts, $this->facts, $this->prompts, $this->usage, $this->callers);
        $this->lock = new Lock();
        $this->meter = new SpendMeter();
        $cleaner = new HtmlCleaner();
        $this->generator = new Generator(
            $this->settings,
            $this->switch,
            $this->usage,
            $this->planner,
            $this->callers,
            $this->drafts,
            $this->prompts,
            new ImagePreparer(),
            $cleaner,
            new FactCheck(),
            $this->meter,
            $this->lock,
            $this->log
        );
        $this->runner = GenQueueRunner::present()
            ? new GenQueueRunner($this->generator, $this->switch, $this->usage, $this->settings)
            : new ActionSchedulerRunner($this->generator, $this->lock);
        $this->queuer = new Queuer($this->runner, $this->callers, $this->log);
        $this->acceptor = new Acceptor($this->drafts, $this->planner, $cleaner, $this->settings, $this->log);
        $this->housekeeping = new Housekeeping($this->drafts, $this->settings);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Forget the services (tests, multisite switches). */
    public static function reset(): void
    {
        self::$instance = null;
        self::$inert = null;
    }

    public static function inertReason(): ?string
    {
        return self::$inert;
    }

    /**
     * Entry point from the main plugin file.
     */
    public static function boot(string $file): void
    {
        add_action('before_woocommerce_init', static function () use ($file): void {
            $class = '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
            if (class_exists($class)) {
                $class::declare_compatibility('custom_order_tables', $file, true);
            }
        });
        add_action('plugins_loaded', [self::class, 'load'], 20);
    }

    public static function load(): void
    {
        self::$inert = null;
        if (!class_exists(self::$wooCommerceClass) || !function_exists('wc_get_product')) {
            self::$inert = 'woocommerce';
        } elseif (!CallerFactory::available()) {
            self::$inert = 'library';
        }
        if (self::$inert !== null) {
            add_action('admin_notices', [self::class, 'inertNotice']);

            return;
        }

        $plugin = self::instance();
        $plugin->runner->register();
        add_action(Housekeeping::HOOK, [$plugin->housekeeping, 'run']);
        add_action('init', [self::class, 'scheduleHousekeeping']);
        add_action('init', [self::class, 'registerPromptDefault'], 20);
        if (is_admin()) {
            (new Admin($plugin))->register();
        }
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('product-copy', new Command($plugin));
        }
        do_action('product_copy_loaded');
    }

    public static function inertNotice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        $message = self::$inert === 'woocommerce'
            ? __('Product copy drafts needs WooCommerce 8.0 or later. It is inactive until WooCommerce is active.', 'wc-product-copy')
            : __('Product copy drafts cannot load the llm-json-php library and no caller is supplied through the product_copy_caller filter. It is inactive.', 'wc-product-copy');
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
    }

    public static function scheduleHousekeeping(): void
    {
        if (!wp_next_scheduled(Housekeeping::HOOK)) {
            wp_schedule_event(Clock::nextDayStart() + 3 * 3600, 'daily', Housekeeping::HOOK);
        }
    }

    /**
     * Offer the built-in prompt to prompt-library as the template's default.
     */
    public static function registerPromptDefault(): void
    {
        if (function_exists('promptlib_register_default')) {
            try {
                promptlib_register_default(PromptBuilder::CONSUMER, PromptBuilder::definition());
                if (function_exists('promptlib_register_consumer')) {
                    $definition = PromptBuilder::definition();
                    /** @var list<array{name: string}> $variables */
                    $variables = $definition['variables'];
                    promptlib_register_consumer(PromptBuilder::TEMPLATE_SLUG, PromptBuilder::CONSUMER, array_column($variables, 'name'));
                }
            } catch (\Throwable $e) {
                // A companion plugin problem must never break this plugin's init.
                (new Log())->add('prompt_fallback', 0, get_class($e));
            }
        }
    }

    public static function activate(): void
    {
        if (get_option(KillSwitch::OPTION, null) === null) {
            add_option(KillSwitch::OPTION, ['enabled' => false, 'paused' => null, 'paused_at' => null], '', false);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Housekeeping::HOOK);
    }

    /** Capability for settings and bulk runs. */
    public static function manageCapability(): string
    {
        /** @var mixed $cap */
        $cap = apply_filters('product_copy_capability_manage', 'manage_woocommerce');

        return is_string($cap) && $cap !== '' ? $cap : 'manage_woocommerce';
    }
}

<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Tests;

use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\LlmJson\Testing\RecordingSleeper;
use IndianOpinion\WcProductCopy\Clock;
use IndianOpinion\WcProductCopy\Credentials;
use IndianOpinion\WcProductCopy\KillSwitch;
use IndianOpinion\WcProductCopy\Plugin;
use IndianOpinion\WcProductCopy\Settings;
use IndianOpinion\WcProductCopy\Tests\Support\FakeCaller;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** 2026-11-15 10:00:00 UTC */
    protected const NOW = 1794736800;

    protected function setUp(): void
    {
        parent::setUp();
        \WpState::reset();
        \WP_CLI::reset();
        Plugin::reset();
        Clock::freeze(self::NOW);
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        \WpState::reset();
        Clock::freeze(null);
        parent::tearDown();
    }

    protected function plugin(): Plugin
    {
        return Plugin::instance();
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function settings(array $values = []): void
    {
        [$clean] = Settings::validate(array_merge(get_option(Settings::OPTION, []) ?: [], $values), false);
        update_option(Settings::OPTION, $clean);
    }

    /** Model route, key and switch on. */
    protected function configure(array $values = []): void
    {
        $this->settings(array_merge(['provider' => 'openrouter', 'model' => 'test/model'], $values));
        update_option(Credentials::OPTION, 'test-key');
        (new KillSwitch())->turnOn();
    }

    protected function fakeCaller(bool $images = false): FakeCaller
    {
        $fake = new FakeCaller($images);
        add_filter('product_copy_caller', static fn () => $fake);

        return $fake;
    }

    protected function mockTransport(MockTransport $mock): void
    {
        add_filter('product_copy_client', static fn (Client $client): Client => $client->withTransport($mock)->withSleeper(new RecordingSleeper()));
    }

    protected function term(int $id, string $name, string $taxonomy, int $parent = 0): \WP_Term
    {
        $site = &\WpState::site();
        $term = new \WP_Term($id, $name, $taxonomy, $parent);
        $site['terms'][$id] = $term;
        if (!in_array($taxonomy, $site['taxonomies'], true)) {
            $site['taxonomies'][] = $taxonomy;
        }

        return $term;
    }

    /**
     * Create a product. Unknown keys go into the product data.
     *
     * @param array<string, mixed> $data
     * @param array<string, list<\WP_Term>> $terms
     */
    protected function product(array $data = [], string $status = 'publish', array $terms = []): int
    {
        $site = &\WpState::site();
        $id = $data['id'] ?? (count($site['products']) + 100);
        $product = new \WC_Product();
        $product->data = array_merge($product->data, ['id' => $id, 'name' => 'Oak chopping board'], $data);
        $site['products'][$id] = $product;
        $site['posts'][$id] = ['type' => 'product', 'status' => $status, 'title' => $product->data['name']];
        foreach ($terms as $taxonomy => $list) {
            $site['object_terms'][$id][$taxonomy] = $list;
        }

        return (int) $id;
    }

    /** A product with enough facts to run. */
    protected function richProduct(array $data = [], string $status = 'publish'): int
    {
        $wood = $this->attribute('Material', ['Oak']);
        $size = $this->attribute('Finish', ['Oiled']);

        return $this->product(array_merge(['attributes' => ['material' => $wood, 'finish' => $size], 'weight' => '1.2'], $data), $status);
    }

    /**
     * @param list<int|string> $options
     */
    protected function attribute(string $name, array $options, bool $visible = true, bool $variation = false, int $taxonomyId = 0): \WC_Product_Attribute
    {
        $attribute = new \WC_Product_Attribute();
        $attribute->set_name($name);
        $attribute->set_options($options);
        $attribute->set_visible($visible);
        $attribute->set_variation($variation);
        $attribute->set_id($taxonomyId);

        return $attribute;
    }

    protected function stored(int $productId): \WC_Product
    {
        $product = wc_get_product($productId);
        $this->assertInstanceOf(\WC_Product::class, $product);

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    protected function &site(): array
    {
        return \WpState::site();
    }

    protected function args(array $args = []): array
    {
        return array_merge(['fields' => 'both', 'regenerate_owned' => false, 'refresh_drafts' => false, 'include_discarded' => false], $args);
    }
}

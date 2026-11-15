<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Tests\Unit;

use IndianOpinion\WcProductCopy\FactCheck;
use IndianOpinion\WcProductCopy\Facts\FactList;
use IndianOpinion\WcProductCopy\Facts\Fact;
use IndianOpinion\WcProductCopy\HtmlCleaner;
use IndianOpinion\WcProductCopy\Settings;
use IndianOpinion\WcProductCopy\Tests\Support\FakeCaller;
use IndianOpinion\WcProductCopy\Tests\TestCase;
use IndianOpinion\WcProductCopy\Text;

/**
 * Cases 41 to 44: clean-up, flags, lengths and normalisation.
 */
final class CheckTest extends TestCase
{
    /** Test 41 (case 41). */
    public function testMarkdownBecomesHtmlAndDangerousMarkupIsRemoved(): void
    {
        $cleaner = new HtmlCleaner();

        $md = $cleaner->clean("Solid oak board.\n\n- **Oiled** finish\n- Juice *groove*\n\n## Care\nWipe clean.")['html'];
        $this->assertSame('<p>Solid oak board.</p><ul><li><strong>Oiled</strong> finish</li><li>Juice <em>groove</em></li></ul><p>Care</p><p>Wipe clean.</p>', str_replace("\n", '', $md));

        $html = $cleaner->clean(
            '<h2 class="x">Title</h2><p onclick="evil()">Good <a href="https://spam.example">wood</a></p>'
            . '<script>alert(1)</script><style>p{}</style><div>Plain <b>bold</b></div><iframe src="x"></iframe>'
        )['html'];
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('alert', $html);
        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringNotContainsString('href', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<h2', $html);
        $this->assertStringNotContainsString('iframe', $html);
        $this->assertStringContainsString('<p>Title</p>', $html);
        $this->assertStringContainsString('<p>Good wood</p>', $html);
        $this->assertStringContainsString('Plain bold', $html);

        $plain = $cleaner->clean("First paragraph.\n\nSecond paragraph.")['html'];
        $this->assertSame('<p>First paragraph.</p>' . "\n" . '<p>Second paragraph.</p>', $plain);
    }

    /** Test 42 (case 42). */
    public function testEveryFlagFiresWithDetails(): void
    {
        $this->settings(['desc_min' => 1, 'desc_max' => 500]);
        $settings = $this->plugin()->settings->get();
        $facts = new FactList('Oak board 40 cm', [new Fact('F1', 'attribute:size', 'Size', '40 x 25 cm'), new Fact('F2', 'weight', 'Weight', '1.2 kg')]);
        $cleaner = new HtmlCleaner();
        $clean = $cleaner->clean(
            '<p>This 40 cm board weighs 1.2 kg and lasts 25 years, guaranteed. Clinically proven. Number one choice, 100% oak, #1. '
            . 'Only €19 at our price, on sale, in stock with free delivery. '
            . 'Visit www.example.com or mail me@example.com or call +44 20 7946 0958.</p>'
        );
        $flags = (new FactCheck())->field('description', $clean['html'], $facts, $settings, $clean['removed'], 'EUR');
        $flags = array_merge($flags, (new FactCheck())->references(['F1', 'F9'], $facts));
        $byCode = [];
        foreach ($flags as $flag) {
            $byCode[$flag['code']] = $flag['detail'];
        }

        $this->assertSame(['100', '1', '19'], $byCode['unverified_number']);
        $this->assertSame(['guaranteed', 'clinically proven', 'number one', '#1', '100%'], $byCode['blocked_phrase']);
        $this->assertContains('€', $byCode['price_mention']);
        $this->assertContains('price', $byCode['price_mention']);
        $this->assertContains('sale', $byCode['price_mention']);
        $this->assertContains('in stock', $byCode['price_mention']);
        $this->assertContains('free delivery', $byCode['price_mention']);
        $this->assertCount(3, $byCode['contact_removed']);
        $this->assertStringNotContainsString('example.com', $clean['html']);
        $this->assertStringNotContainsString('7946', $clean['html']);
        $this->assertSame(['F9'], $byCode['bad_fact_ref']);
        $this->assertArrayNotHasKey('25', array_flip($byCode['unverified_number']), '25 is in a fact value.');
    }

    public function testBlockedPhrasesFilterAddsPhrases(): void
    {
        add_filter('product_copy_blocked_phrases', static fn (array $p): array => array_merge($p, ['artisan']));
        $flags = (new FactCheck())->field('short', '<p>An artisan board.</p>', new FactList('Board', []), Settings::fromArray([]), [], '');

        $this->assertSame(['artisan'], array_values(array_filter($flags, static fn ($f) => $f['code'] === 'blocked_phrase'))[0]['detail']);
    }

    public function testFlagsAreSavedOnTheDraftAndASiteCanAddOne(): void
    {
        $this->configure();
        $this->fakeCaller()->push(FakeCaller::answer(['description_html' => str_repeat('Guaranteed best wood. ', 30), 'used_facts' => ['F1', 'F99']]));
        add_filter('product_copy_flags', static function (array $flags): array {
            $flags[] = ['code' => 'site_check', 'field' => 'description', 'detail' => ['custom']];

            return $flags;
        });
        $id = $this->richProduct();

        $draft = product_copy_generate_now($id);
        $codes = array_column($draft->flags, 'code');

        $this->assertContains('blocked_phrase', $codes);
        $this->assertContains('bad_fact_ref', $codes);
        $this->assertContains('site_check', $codes);
        $this->assertTrue($draft->isFlagged());
    }

    /** Test 43 (case 43). */
    public function testLengthFlagsAndCharacterCountingForJapanese(): void
    {
        $check = new FactCheck();
        $facts = new FactList('Board', []);
        $settings = Settings::fromArray(['short_min' => 15, 'short_max' => 60]);

        $long = '<p>' . str_repeat('word ', 61) . '</p>';
        $thin = '<p>Too short.</p>';
        $this->assertSame('too_long', $check->field('short', $long, $facts, $settings, [], '')[0]['code']);
        $this->assertSame('thin', $check->field('short', $thin, $facts, $settings, [], '')[0]['code']);
        $this->assertSame([], $check->field('short', '<p>' . str_repeat('word ', 20) . '</p>', $facts, $settings, [], ''));

        \WpState::$locale = 'ja';
        $this->assertTrue($settings->countsCharacters());
        $this->assertSame(['min' => 30, 'max' => 120, 'unit' => 'characters'], $settings->lengths('short'));
        $ja = '<p>' . str_repeat('木', 40) . '</p>';
        $this->assertSame([], $check->field('short', $ja, $facts, $settings, [], ''));
        $this->assertSame('too_long', $check->field('short', '<p>' . str_repeat('木', 121) . '</p>', $facts, $settings, [], '')[0]['code']);
        $this->assertSame(40, Text::length($ja, true));
    }

    /** Test 44 (case 44). */
    public function testNormalisationMakesEntityAndWhitespaceVariantsHashTheSame(): void
    {
        $a = "<p>Salt &amp; pepper\r\n mill&nbsp; </p>";
        $b = "<p>Salt & pepper mill</p>";
        $c = "  <p>Salt &#38; pepper\n\n\tmill</p>";

        $this->assertSame(Text::hash($a), Text::hash($b));
        $this->assertSame(Text::hash($b), Text::hash($c));
        $this->assertNotSame(Text::hash('<p>Salt</p>'), Text::hash('<div>Salt</div>'), 'Tags are kept.');
    }

    public function testEmptyMeansNothingAfterTagsEntitiesAndSpaces(): void
    {
        $this->assertTrue(Text::isEmpty("<p>&nbsp;</p>\n<br>\u{00A0}"));
        $this->assertFalse(Text::isEmpty('<p>x</p>'));
    }

    public function testAllowedHtmlFilterCannotAllowLinksOrAttributes(): void
    {
        add_filter('product_copy_allowed_html', static fn (array $tags): array => $tags + ['a' => ['href' => true], 'h3' => ['class' => true]]);
        $html = (new HtmlCleaner())->clean('<h3 class="c">Head</h3><p><a href="x">link</a></p>')['html'];

        $this->assertSame('<h3>Head</h3><p>link</p>', $html);
    }
}

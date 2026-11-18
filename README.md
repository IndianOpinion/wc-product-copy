# wc-product-copy

Writes WooCommerce product descriptions and short descriptions from the
store's own product data, as drafts an editor reads, edits and accepts. It
never overwrites text a person wrote, and every bulk run starts with a dry
run that spends nothing.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from
scratch; it is independent of and not affiliated with any third-party
plugin it integrates with.

Measured: a dry run over 1,000 products took 1.1 seconds and 4.4 MB on the
test stub layer (no database). Acceptance and flag rates on a real catalogue
are not measured yet.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [WP-CLI](#wp-cli)
- [Hooks and functions](#hooks-and-functions)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

Requirements: PHP 8.1 or later, WordPress 6.4 or later, WooCommerce 8.0 or
later. The image resize uses the WordPress image editor (GD or Imagick).

**From a release zip (no Composer needed).** The zip bundles its one runtime
dependency, [llm-json-php](https://github.com/IndianOpinion/llm-json-php),
in `vendor/`. Upload it under Plugins > Add New, or unzip it into
`wp-content/plugins/wc-product-copy/`, then activate.

**From source, with Composer.**

```sh
cd wp-content/plugins
git clone https://github.com/IndianOpinion/wc-product-copy.git
cd wc-product-copy
composer install --no-dev --optimize-autoloader
```

**From source, without Composer.** Copy llm-json-php into
`lib/llm-json-php/` inside the plugin folder; `autoload.php` loads its
`bootstrap.php`. Or skip the library and supply your own caller (see
[`examples/custom-caller.php`](examples/custom-caller.php)).

**As a must-use plugin.** Copy the folder to
`wp-content/mu-plugins/wc-product-copy/` and
[`examples/mu-plugin-loader.php`](examples/mu-plugin-loader.php) to
`wp-content/mu-plugins/wc-product-copy-loader.php`.

The plugin is **off after install**. Nothing is sent anywhere until you set
a model and key and turn it on.

## Quick start

1. Products > Copy settings: choose a provider, a model, paste the API key
   (or define `PRODUCT_COPY_API_KEY` in `wp-config.php`), and check the
   daily call cap (50 by default). Save.
2. Press "Turn generation on".
3. On the products list, tick some products, choose the bulk action
   "Write copy drafts". The review screen is a dry run: it shows, per
   product, which fields it would write, or why it skips the product. Press
   "Queue N products".
4. Drafts appear on each product's edit screen in the "Copy drafts" box and
   under Products > Copy drafts. Read, edit, accept or discard.

From a terminal:

```sh
wp product-copy generate --missing=any --dry-run
wp product-copy generate --missing=any --yes
wp product-copy drafts --state=pending
wp product-copy accept 123 --user=editor
```

Action Scheduler runs the jobs through WP-Cron. On a quiet site, run cron
from the system, for example every minute:

```
* * * * * cd /path/to/site && wp cron event run --due-now >/dev/null 2>&1
```

## Configuration

Products > Copy settings. Option `product_copy_settings`.

| Setting | Default | Notes |
|---|---|---|
| Generation switch | off | Also `wp product-copy pause` / `resume`. `PRODUCT_COPY_DISABLED` true forces it off. |
| Provider | openrouter | `openrouter`, `openai` or `generic` (any chat completions endpoint, with a base URL). |
| Model | none | Any model the provider serves. |
| API key | none | Stored in its own option, never printed back. `PRODUCT_COPY_API_KEY` wins. |
| Route accepts images | off | Only matters with a caller that can send images; the built-in adapter is text-only. |
| Daily call cap | 50 | 1 to 10,000. Counts attempts, billed failures included. `PRODUCT_COPY_DAILY_CALL_CAP` can only lower it. |
| Daily cost cap | off | Needs an assumed cost per call. `PRODUCT_COPY_DAILY_COST_CAP` overrides it. |
| Assumed cost per call | none | Used when a call's cost is unknown. |
| Price table | none | `model input-price output-price` per line, per million tokens. |
| Fields | both | `description`, `short` or `both`. |
| Language | site locale | Sent to the prompt. |
| Tone note | empty | Up to 300 characters. |
| Description length | 80 to 300 words | |
| Short description length | 15 to 60 words | |
| Languages counted in characters | ja, zh, th, lo, km, my | Limits doubled and counted in characters. |
| Minimum facts | 2 | Below it, with no image, the product is skipped. |
| Send image | on | Only when the route and the caller accept images. |
| Per-image token estimate | 1,000 | Dry run only. |
| Include SKU and codes | off | |
| Include weight and dimensions | on | |
| Visible attributes only | on | Variation attributes are always included on variable products. |
| Blocked phrases | guaranteed, clinically proven, cures, best in the world, number one, #1, 100% | One per line. |
| Output token cap | 1,500 | |
| Retention | 90 days | Old drafts lose their text, keep state and hashes. |

Per product, in the "Copy drafts" box: **Notes for the writer** (true facts
the store data lacks, one per line) and **Do not write copy for this
product**.

## How it works

1. **Facts.** For each product it builds a numbered list: writer notes,
   brands, product type, category paths, attributes (term names, never
   slugs), weight and dimensions, tags, and codes if enabled. Prices, stock,
   shipping, tax, reviews, orders, customers, URLs and the current
   descriptions are never sent, even if a filter adds them.
2. **Eligibility.** A field is written only when it is empty, or when it
   holds text this plugin accepted earlier and you ask to regenerate it.
   Anything else is a person's text and is never touched.
3. **Dry run.** Every bulk run shows what would happen first: decisions,
   reasons, fact counts, estimated tokens, and how many calls today's cap
   allows. It makes no call and writes nothing.
4. **Jobs.** Each product is one job, keyed by its input, so pressing the
   button twice queues nothing new. Jobs run through gen-queue if it is
   active, else through Action Scheduler (part of WooCommerce), one at a
   time. Each job reads the switch and caps afresh, locks the product,
   checks eligibility again, asks spend-meter if it is active, then makes
   one call. The prompt is the built-in one, or the prompt-library template
   `product-copy-generate` when prompt-library is active (the built-in
   prompt is offered to it as that template's default). The product title
   and facts are sent inside `<title>` and `<facts>` blocks marked as data.
5. **Checks.** The answer is cleaned (simple HTML only, no links, no
   scripts, no contact details) and flagged: numbers not in the facts,
   blocked phrases, price or stock talk, removed contact details, length,
   and fact ids that do not exist. Flags never block a draft; they tell the
   editor where to look.
6. **Drafts.** The draft is post meta only. The product does not change
   until an editor accepts.
7. **Accept.** The field is read again; if a person filled it meanwhile,
   accept is refused and the draft is shown for copying. Otherwise the text
   is cleaned again and saved through the WooCommerce product object, and
   its hash is stored so a later edit by a person is recognised. "Undo"
   restores the previous value while the field is unchanged.

Details and every design decision: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).
Requirements: [docs/PRD.md](docs/PRD.md).

## WP-CLI

| Command | Effect |
|---|---|
| `wp product-copy generate [<id>...] [--category=<slug>] [--status=<s>] [--type=<t>] [--missing=<which>] [--fields=<f>] [--all] [--limit=<n>] [--regenerate-owned] [--refresh-drafts] [--include-discarded] [--dry-run] [--show-prompt=<id>] [--now] [--yes] [--format=table\|json\|csv]` | Plan, confirm, queue. No selection is an error; there is no implicit "all". `--now` runs in the foreground, still capped. |
| `wp product-copy drafts [--state=<s>] [--flagged] [--format=<f>]` | List drafts. |
| `wp product-copy show <id>` | One draft with flags and facts. |
| `wp product-copy accept <id>... [--field=<f>] [--accept-stale] --user=<login>` | Accept as that user. |
| `wp product-copy discard <id>... --user=<login>` | Discard. |
| `wp product-copy status [--format=json]` | Switch, caps, usage today, queued, pending, flagged, failed, runner. |
| `wp product-copy pause` / `resume` | The switch. |

Exit codes: 0 success, 1 error, 2 nothing to do, 3 paused, 4 cap reached.

## Hooks and functions

Functions: `product_copy_plan()`, `product_copy_queue()`,
`product_copy_generate_now()`, `product_copy_get_draft()`,
`product_copy_accept()`, `product_copy_discard()`, `product_copy_undo()`,
`product_copy_field_state()`, `product_copy_is_enabled()`,
`product_copy_usage_today()`.

Actions: `product_copy_loaded`, `product_copy_queued`,
`product_copy_batch_queued`, `product_copy_skipped`,
`product_copy_draft_saved`, `product_copy_failed`, `product_copy_accepted`,
`product_copy_discarded`, `product_copy_undone`, `product_copy_cap_reached`,
`product_copy_paused`.

Filters: `product_copy_facts`, `product_copy_brand_taxonomies`,
`product_copy_prompt`, `product_copy_image_max_side`,
`product_copy_allowed_html`, `product_copy_blocked_phrases`,
`product_copy_flags`, `product_copy_eligible`, `product_copy_client`,
`product_copy_caller`, `product_copy_capability_manage`,
`product_copy_retention_days`.

Examples: [`examples/site-filters.php`](examples/site-filters.php),
[`examples/custom-caller.php`](examples/custom-caller.php), and
`php examples/demo.php`, which runs the whole flow offline against the test
stub layer.

## Limitations

- The built-in llm-json-php adapter sends text only. To send the product
  image, supply your own caller through `product_copy_caller`.
- The "Copy drafts" box is on the classic product editor. With the
  block-based product editor, use Products > Copy drafts.
- Parent products only: variation descriptions are not written.
- One language per site. No alt text, titles, SEO fields or translations.
- The fact check is a guide, not a proof: it catches numbers and phrases,
  not every invented claim. A person reads every draft.
- Without a persistent object cache, the product lock is a transient with a
  read-back check, not an atomic lock.

## FAQ

**Will it overwrite a description I wrote?** No. A field with text it did
not write is skipped when choosing work and checked again at accept time.
The only way to replace it is the per-product "Replace" button with its own
confirmation tick, and the old text is kept for undo.

**Why is nothing happening?** Run `wp product-copy status`. Check that the
switch is on, that a model and key are set, that today's call cap is not
reached, and that WP-Cron runs (or run `wp action-scheduler run`).

**Does it publish anything?** No. It never changes a product's status. It
writes the description only when an editor accepts a draft.

**What leaves my site?** The product title, the fact list and, only with a
caller that supports images, one resized copy of the product image with its
metadata removed. Never prices, stock, orders, customers or reviews.

**Can it read competitor or manufacturer pages?** No. Only the store's own
data, by design.

**How do I stop it right now?** `wp product-copy pause`, the button on the
drafts screen, or `define('PRODUCT_COPY_DISABLED', true);`. A call already
in flight finishes; no new call starts.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security reports:
[SECURITY.md](SECURITY.md).

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See [LICENSE](LICENSE).

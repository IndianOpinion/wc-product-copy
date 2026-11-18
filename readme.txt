=== Product Copy Drafts ===
Contributors: indianopinion
Tags: woocommerce, product description, drafts, ai, bulk
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Writes WooCommerce product descriptions from the store's own data, as drafts an editor accepts. Never overwrites text a person wrote.

== Description ==

Product Copy Drafts writes a product description and a short description from the store's own product data: title, categories, tags, attributes, weight and dimensions, and the editor's notes. It uses a language model through your own API key.

Every result is a draft. Nothing reaches the product until an editor reads it, edits it if needed, and accepts it. Text a person wrote is never overwritten.

Written from scratch; not derived from any third-party plugin.

* Bulk runs from the products list or WP-CLI, always after a dry run that spends nothing.
* A daily call cap (50 by default), an optional daily cost cap and a kill switch.
* Facts only from your store. Prices, stock, orders, customers and reviews are never sent.
* Flags for numbers not in the facts, blocked phrases, price talk, removed links and lengths.
* Accept writes through the WooCommerce product object. Undo while the text is unchanged.
* Works with Action Scheduler (part of WooCommerce), or gen-queue and spend-meter when active.

The plugin is off after install. Set a model and API key under Products > Copy settings, then turn it on.

== Installation ==

1. Upload the plugin zip under Plugins > Add New, or unzip it into `wp-content/plugins/wc-product-copy/`.
2. Activate it. WooCommerce 8.0 or later must be active.
3. Go to Products > Copy settings, choose a provider and model, paste the API key, save, and turn generation on.
4. On the products list, select products and choose the bulk action "Write copy drafts".

== Frequently Asked Questions ==

= Will it change my existing descriptions? =

No. Fields with text a person wrote are skipped, and checked again when a draft is accepted.

= Does it publish products? =

No. It never changes a product's status.

= What is sent to the model? =

The title, a list of facts from the product's own data and, only with a caller that supports images, one resized copy of the product image with its metadata removed.

= How do I stop it? =

Press "Pause generation" on Products > Copy drafts, run `wp product-copy pause`, or define `PRODUCT_COPY_DISABLED` as true.

== Changelog ==

= 0.1.0 =
* First release.

# Changelog

All notable changes to this project are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-18

### Added

- Fact list from the store's own product data: writer notes, brands, type,
  category paths, attributes as term names, weight and dimensions, tags,
  optional codes. Prices, stock, shipping, tax, reviews, URLs and current
  descriptions are never sent, even if a filter adds them.
- Empty, owned and manual field states; manual text is never written.
- Dry run on the review screen and `wp product-copy generate --dry-run`,
  with cap headroom; `--show-prompt`.
- Jobs through gen-queue when active, else Action Scheduler; idempotent
  queueing in batches of 500.
- Kill switch, daily call cap, optional daily cost cap with an assumed cost,
  spend-meter integration, per-product lock.
- Built-in generic prompt, prompt-library integration, `product_copy_prompt`.
- `ModelCaller` interface, the llm-json-php reference adapter (text only),
  and the `product_copy_caller` filter.
- Image picking, same-host fetch, resize, JPEG re-encode and metadata strip
  for callers that accept images.
- HTML clean-up and flags: unverified numbers, bad fact ids, blocked
  phrases, price mentions, removed contact details, length.
- "Copy drafts" meta box, Products > Copy drafts screen with bulk accept and
  discard, Products > Copy settings with the event log.
- Accept, explicit replace, discard, undo, stale detection.
- Daily housekeeping of old draft text; uninstall that keeps accepted
  descriptions.
- WP-CLI: `generate`, `drafts`, `show`, `accept`, `discard`, `status`,
  `pause`, `resume`.

[Unreleased]: https://github.com/IndianOpinion/wc-product-copy/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/wc-product-copy/releases/tag/v0.1.0

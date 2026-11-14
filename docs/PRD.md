# wc-product-copy: product requirements

Status: draft for review. Version 0.1.0 target.

wc-product-copy is a WordPress plugin for WooCommerce stores. It writes a
product description and a short description from the store's own product
data (title, categories, tags, attributes, a few optional fields, writer
notes and the product image) through a language model. Every result is a
draft that an editor reads, edits and accepts. It never overwrites a
description a person wrote. It works one product at a time or in bulk, and
every bulk run starts with a dry run.

Written from scratch; not derived from any third-party plugin.

Names in this document (functions, hooks, options, meta keys, commands) are
this project's own and are the public contract. The prefix for functions,
hooks and options is `product_copy_`. The prefix `wc_` belongs to
WooCommerce and is never used.

## 1. Problem

A store with a few hundred products usually has many with an empty
description or a one-line short description. Writing them by hand is slow,
so they stay empty. Empty pages convert badly, look abandoned and give
search engines nothing to index.

Writing them with a language model by pasting data into a chat window is
slow too, and it fails in known ways:

- The model invents specifications the product does not have (a material,
  a size, a certification, a warranty).
- It states prices, stock or delivery promises that are wrong a week later.
- A careless bulk script overwrites descriptions the owner wrote by hand.
- A loop with no cap spends money all night on retries.
- Output arrives as Markdown, or with scripts and links, and is pasted
  straight into the page.

This plugin fixes those by building a fact list from the store's own data,
asking for copy that uses only those facts, checking the answer against
them, and keeping a person between the model and the live page.

Measured result: none yet. The builder records one line in the README from
a test catalogue (for example, the share of drafts accepted without edits,
and the share flagged by the fact check), with no costs.

## 2. Goals and non-goals

### Goals

1. Fill empty product descriptions and short descriptions with factual
   drafts built only from the store's own product data.
2. Keep a person in the loop: nothing reaches the product until an editor
   accepts it.
3. Never overwrite text a person wrote, at generation time or at accept
   time.
4. Bulk runs over a whole catalogue, always preceded by a dry run that
   spends nothing and writes nothing.
5. A daily cap on calls and an optional daily cost cap, plus a kill switch,
   in the plugin's own settings, even when a shared queue or spend meter is
   present.
6. Flag claims the facts do not support, so the editor knows what to check.
7. Work through `llm-json-php` for every model call, and integrate softly
   with `gen-queue`, `spend-meter` and `prompt-library` when they are
   active.

### Non-goals

- Fetching anything from other websites: no competitor pages, no
  manufacturer pages, no marketplace listings, no product feeds from
  elsewhere. Only the store's own data.
- Publishing. The plugin never changes a product's status and never
  publishes a product.
- Variation descriptions (each variation's own description field). Parents
  only in 0.1.0 (see open question Q2).
- Product titles, SEO titles, meta descriptions, image alt text, category
  descriptions, translations.
- Image generation or editing.
- A REST API. Admin screens and WP-CLI only in 0.1.0.
- Writing in a house style beyond a short operator-supplied tone note.
- Reviews, order data or customer data as inputs. Never.

## 3. Users and setting

- **Store owner or manager** (capability `manage_woocommerce`): configures
  the model route, caps and switch, starts bulk runs, reads the dry run.
- **Editor** (capability `edit_product` on the product): reads drafts,
  edits them, accepts or discards them.
- **Operator at a terminal**: runs WP-CLI for large catalogues and
  scheduled runs.

It runs inside WordPress admin and WP-CLI on the store's own host. It adds
nothing to the shop front end: no scripts, no styles, no queries on
customer-facing pages.

## 4. Behaviour

Vocabulary used below:

- **Field**: `description` (the long description, post content) or `short`
  (the short description, post excerpt).
- **Empty**: a field whose value, after decoding entities, stripping tags
  and removing all whitespace including non-breaking spaces, has length 0.
- **Owned**: a field whose normalised value (case 44) hashes to the hash
  this plugin stored when it last accepted a draft into that field.
- **Manual**: any field that is neither empty nor owned.
- **Fact**: one labelled value from the product, with an id (`F1`, `F2`,
  and so on).
- **Day**: the calendar day in the site's time zone.

### 4.1 Building the fact list

1. The fact list is built from, in this order: writer notes (case 3),
   brand terms (when a brand taxonomy exists; default taxonomy name
   `product_brand`, filterable), the product type in plain words, category
   names with their full path (`Parent > Child`), attributes, weight and
   dimensions with the store's units, and tags. The title is always sent
   separately and is not a numbered fact.
2. Attributes: taxonomy attributes are sent as term names, never slugs.
   Custom attributes are sent as their values. By default only attributes
   marked visible on the product page are used. On a variable product,
   attributes used for variations are sent as "available in: a, b, c".
3. Writer notes: a plain text field in the product's meta box ("Notes for
   the writer", up to 1,000 characters). It is the editor's way to add
   true facts the store data lacks. Each non-empty line becomes one fact.
4. SKU, GTIN and similar codes are excluded by default and can be turned
   on in settings. Weight and dimensions are included by default when set.
5. Never sent, whatever the settings or filters: regular price, sale price,
   stock status and quantity, shipping class, tax class, reviews and
   ratings, order data, customer data, URLs, the product's own current
   description or short description, and any other product's text. The
   `product_copy_facts` filter output is checked again after the filter
   runs: a fact whose value contains a URL or an email address is dropped
   and logged.
6. Every value is entity-decoded, stripped of tags, whitespace-collapsed
   and cut to 200 characters (on a character boundary, never inside a
   multibyte character). Empty values are dropped. Duplicate label and
   value pairs are dropped. At most 60 facts are kept, in the order of
   case 1; the rest are dropped and counted in the draft record.
7. The filter `product_copy_facts` receives the fact list and the product
   and may add, change or remove facts. It is documented as "the store's
   own data only".
8. **Insufficient data.** When the fact list has fewer facts than the
   minimum (default 2) and no image will be sent, the product is skipped
   with reason `insufficient_data`. No call is made. The plugin does not
   write copy from a title alone.

### 4.2 The product image

9. The image is the featured image, or else the first gallery image, or
   else none. Image sending is on by default and can be turned off. It is
   also off when the configured route is not marked as accepting images.
10. The image is read from the local upload directory, resized so the
    longest side is at most 1,024 pixels (filterable), and re-encoded as
    JPEG at quality 82 through the WordPress image editor. Re-encoding
    drops embedded metadata (location, camera, author). It is sent inline
    as a base64 data URL, never as a public link, so private and staging
    stores work.
11. When the file is not on local disk (an offloaded uploads directory),
    the plugin fetches it over HTTP only if the URL's host equals the
    site's host or the uploads base URL's host, with a 10 second timeout
    and a 10 MB limit. Any other host, a redirect to any other host, a
    timeout, a non-image content type or an oversize body means no image,
    and the draft records `image_unavailable`.
12. SVG images are never sent. Animated images are sent as their first
    frame as produced by the image editor. A file the image editor cannot
    open means no image and `image_unavailable`.

### 4.3 Eligibility and selection

13. A field is eligible when it is empty, or when it is owned and the run
    allows regenerating owned text (off by default; `--regenerate-owned`
    on the CLI, a checkbox on the bulk screen).
14. A manual field is never eligible. It is skipped with reason
    `manual_text`, and the plugin never writes it.
15. A run asks for `description`, `short` or `both` (default `both`). When
    one requested field is eligible and the other is not, the draft covers
    only the eligible one, and the prompt asks only for it.
16. Product types: simple, variable, grouped and external products are
    eligible. Variations are never processed. Statuses: published, draft,
    pending, private and scheduled are eligible. Trashed and auto-draft
    products are never processed.
17. A product with the "Do not write copy for this product" box ticked is
    skipped with reason `excluded`.
18. A product with a pending draft whose input hash equals the current
    input hash is skipped with reason `draft_current`. With a different
    hash it is skipped with reason `draft_pending` unless the run asks to
    refresh drafts; then the old draft is replaced.
19. A product whose last draft for the same input hash was discarded is
    skipped with reason `discarded` unless the run asks to include
    discarded products.
20. **Input hash.** SHA-256 over: the canonical JSON of the title and the
    fact list, the image's attachment id and file modification time (or
    none), the prompt's content hash, the requested fields, the length
    limits, the language and the tone note. It decides cases 18, 19 and 49.

### 4.4 Dry run and bulk

21. **Bulk from the products list.** A bulk action "Write copy drafts" on
    the products list opens a review screen. That screen is a dry run of
    the selection. It never queues anything by itself. A button "Queue N
    products" does, after a nonce and capability check.
22. **CLI selection.** `wp product-copy generate` takes product ids, or
    filters (`--category`, `--status`, `--type`, `--missing`), or `--all`.
    With none of these it exits with an error. There is no implicit "all".
23. **Dry run.** With `--dry-run` (CLI) or on the review screen, the plugin
    builds every fact list and eligibility decision and makes no model
    call, no meta write, no queue write and no counter change. It reports,
    per product: id, title, fields it would ask for, decision and reason,
    fact count, image yes or no, and estimated input tokens (text length
    divided by 4, plus a flat per-image estimate from settings). Totals:
    products by decision, calls, estimated tokens.
24. The dry run reports cap headroom: how many calls today's remaining
    call cap allows, and how many would wait for later days.
25. `--show-prompt=<id>` prints the rendered prompt for one product, with
    the image replaced by a placeholder line. It makes no call.
26. Without `--dry-run`, the CLI prints the same report and asks for
    confirmation before queueing, unless `--yes` is given.
27. Selection pages through product ids 200 at a time and never loads the
    whole catalogue into memory. Queueing more than 500 products is done
    in batches of 500.
28. Each queued product is one job, with the idempotency key
    `product-copy:<product id>:<first 16 hex of the input hash>`, so
    queueing the same selection twice queues nothing new.

### 4.5 Generation

29. At the start of each job the plugin reads the kill switch, the call cap
    and the cost cap afresh. It does not reuse values read at the start of
    a batch.
30. **Kill switch.** When the switch is off (option or constant), no job
    makes a call. Queued jobs wait. Queueing is still allowed and the
    screens say the plugin is paused.
31. **Call cap.** The number of model calls attempted today, including
    failed attempts that were billed, may not exceed the daily call cap
    (default 50, minimum 1, no "unlimited"). When it is reached, remaining
    jobs wait for the next day. The cap counts attempts, not products.
32. **Cost cap.** Optional, off by default. When set, a job runs only if
    today's recorded cost plus the job's estimate is within the cap. Cost
    comes from the call result (provider-reported cost, or the configured
    price table). A call whose cost is unknown is recorded at the
    "assumed cost per call" setting, which is required when a cost cap is
    set (settings validation enforces it).
33. **spend-meter present** (`defined('SPEND_METER_API')` and equal to 1):
    before each call the plugin applies `spend_meter_pre_spend` with
    purpose `product-copy.generate`, caller `wc-product-copy`, the
    provider and model, the estimated input tokens and the output token
    cap. If `allowed` is not exactly true, no call is made: a reason
    starting `kill_switch` pauses the plugin's job type; any other reason
    makes the job wait for the next day. After the call it fires
    `spend_meter_record` with the reservation id, token counts, the
    provider-reported cost when known, and status `ok`, `error_billed` or
    `error_unbilled`; when no call was made after a reservation it fires
    `spend_meter_release`. The plugin's own caps still apply.
34. **Lock.** A per-product lock (10 minute expiry) stops two workers
    generating for the same product. The second one skips with reason
    `locked` and its job ends without a call.
35. **Re-check.** The job recomputes eligibility (cases 13 to 19) and the
    input hash at run time. A field that became manual since queueing is
    dropped; if no field is left the job ends with reason `manual_text`
    and no call.
36. **Product gone.** A product that was deleted or trashed after queueing
    ends the job with reason `gone` and no call.
37. **Prompt.** When `prompt-library` is active the prompt is rendered
    from the template slug `product-copy-generate` with consumer id
    `wc-product-copy`; the plugin registers its built-in prompt as that
    template's default. Otherwise the built-in prompt is used. The filter
    `product_copy_prompt` can replace either.
38. The built-in prompt is generic. It asks the model to: write in the
    configured language; use only the listed facts and what is plainly
    visible in the image; never invent specifications, materials,
    certifications, compatibility, origin, warranties or awards; never
    mention price, discounts, stock, delivery or returns; never address
    competitors; write the requested fields within the given lengths;
    return the ids of the facts it used; and add a short note for the
    editor when the facts were thin. It includes the tone note when one is
    set. It carries no store-specific rules.
39. **Call.** One call per job through `llm-json-php`, with a JSON schema:
    `description_html` (string or null), `short_html` (string or null),
    `used_facts` (array of fact id strings), `note` (string, at most 300
    characters). The message list holds the system text, the user text
    and, when an image is sent, one image part.
40. A requested field that is missing, null or empty in the answer fails
    the attempt as invalid output (the library's own retry rules then
    apply). A field that was not requested is ignored.
41. **HTML clean-up.** If the text has no tags but has Markdown markers
    (lines starting with `- ` or `* `, `**bold**`, `*italic*`), those are
    converted to HTML first. Then the text is passed through `wp_kses` with
    only `p`, `br`, `ul`, `ol`, `li`, `strong` and `em` allowed, with no
    attributes. Headings become paragraphs. Plain text with blank lines
    becomes paragraphs.
42. **Fact check flags.** The cleaned text is checked and the draft keeps
    every flag. Flags never block a draft; they guide the editor:
    - `unverified_number`: a number (digits, with optional decimal point
      or comma) that appears in neither the title nor any fact value.
      The numbers are listed.
    - `bad_fact_ref`: a `used_facts` id that is not in the fact list.
    - `blocked_phrase`: a phrase from the blocked list (settings; default
      list: "guaranteed", "clinically proven", "cures", "best in the
      world", "number one", "#1", "100%"), matched case-insensitively on
      word boundaries.
    - `price_mention`: a currency symbol, the store's currency code, or
      the words "price", "discount", "sale", "in stock", "free delivery".
    - `contact_removed`: a URL, email address or phone-number-like string
      was found and removed.
    - `too_long` or `thin`: the field is above the maximum or below the
      minimum length (case 43).
43. **Lengths.** Defaults: description 80 to 300 words, short 15 to 60
    words. Words are counted on the plain text by splitting on whitespace.
    For languages written without spaces (a settings list of locale
    prefixes, default `ja`, `zh`, `th`, `lo`, `km`, `my`), length is
    counted in characters and the limits are multiplied by 2.
44. **Normalisation for hashes.** Normalised text is: entity-decoded, line
    endings as `\n`, runs of whitespace collapsed to one space, trimmed.
    Tags are kept. The hash is SHA-256 of the normalised text.
45. **Saving a draft.** The draft is stored on the product as post meta
    only. The product's content, excerpt, status and modified date do not
    change. The record holds: state `pending`, the drafted fields, flags,
    the model's note, used facts, the fact list as sent, the input hash,
    the prompt version and content hash, the reported model, token counts,
    cost and its source, the job id, and the time.
46. **Failures.** When the call fails after the library's retries, the job
    follows the queue's retry rules. When attempts run out, the draft
    record gets state `failed`, the exception class and a short message
    (no raw model output, no prompt text). A credit or authentication
    failure pauses the plugin's job type and shows an admin notice to
    users who can manage the plugin.
47. **Switch flipped mid-run.** A call already in flight finishes and its
    draft is saved. No new call starts.

### 4.6 Review and accept

48. **Meta box.** On the classic product edit screen a box "Copy drafts"
    shows, per drafted field: the current value (or "empty"), the draft in
    an editable text area, the flags with the flagged words highlighted,
    the facts used, the model's note, and buttons: Accept, Discard,
    Regenerate. It also holds the writer notes field and the exclude box.
49. **Stale.** When the product's current input hash differs from the
    draft's, the draft is marked stale. Accepting a stale draft needs an
    extra "accept anyway" tick.
50. **Drafts screen.** A screen under Products, "Copy drafts", lists drafts
    with filters by state (pending, flagged, stale, failed, accepted,
    discarded) and by category, and columns for product, fields, flags,
    age and model. Bulk actions: accept and discard. Bulk accept takes
    only pending drafts with no flags, not stale, whose target fields are
    still empty or owned; every other row is skipped and reported with its
    reason.
51. **Accept checks.** Accept needs a valid nonce and `edit_product` on
    that product. At accept time the plugin re-reads the target field. If
    it is now manual, the accept is refused with reason `manual_text`, the
    draft stays pending, and the screen shows the draft text in a
    read-only copy box so the editor can paste it themselves if they
    choose.
52. **Accept write.** Accept writes through the WooCommerce product object
    (set the description or short description, then save), so WooCommerce
    caches, lookup data and hooks stay correct. It does not touch the
    product's status. It then reads the field back, normalises it (case
    44), stores its hash as the owned hash for that field, records the
    previous value for undo, and sets the draft state to `accepted` with
    the user id and time.
53. **Edited drafts.** Text the editor changed in the text area is cleaned
    by the same `wp_kses` rules (case 41) before it is written. The owned
    hash is taken from what was saved.
54. **Later edits.** Once a person edits an accepted field, its hash no
    longer matches, and from then on the field is manual.
55. **Undo.** After an accept, "Undo" restores the previous value, only
    while the field still equals the accepted text (by owned hash). If the
    field has changed since, undo is refused with reason `changed`.
56. **Discard.** Discard sets the state to `discarded` and keeps the
    record, so case 19 can skip the product. Regenerate on a single
    product ignores case 19, queues one job and is subject to every cap.
57. **Retention.** Drafts in `pending` or `failed` older than the retention
    period (default 90 days) and records in `accepted` or `discarded`
    older than the same period lose their text, keeping only state, hashes
    and times. A daily housekeeping event does this.

### 4.7 Runners, dependencies and environment

58. **With gen-queue** (`defined('GEN_QUEUE_VERSION')`): the plugin
    registers the job type `product-copy.generate` on
    `gen_queue_register_types` and enqueues through it. It vetoes its own
    jobs through `gen_queue_can_run` while its switch is off or a cap is
    reached, so they wait rather than fail. gen-queue's own cap and switch
    apply as well. The plugin reports each call's cost through the job
    context, marked as metered when spend-meter already recorded it.
59. **Without gen-queue:** the plugin uses Action Scheduler, which ships
    with WooCommerce, with one async action per product in the group
    `product-copy`, at most one running at a time, and the same checks
    (cases 29 to 36). A job that waits on a cap is rescheduled for the
    start of the next day.
60. **llm-json-php** is a required dependency, bundled in the release zip.
    If its classes cannot be loaded, the plugin shows an admin notice and
    stays inert: no menus that would fail, no fatal error.
61. **WooCommerce inactive:** admin notice, plugin inert, no fatal error.
62. **Not configured** (no model route or no API key): screens explain what
    is missing; the dry run still works; queueing is refused with reason
    `not_configured`.
63. **Multisite:** settings, caps, counters and drafts are per site. Network
    activation is allowed; nothing is shared across sites.
64. **Uninstall** removes everything listed in section 6 except product
    descriptions that were accepted, which are store content.

### 4.8 Failure modes summary

| Failure | Behaviour | Open or closed | Why |
|---|---|---|---|
| Kill switch off | No calls; jobs wait | Closed | Spend stops at once |
| Call or cost cap reached | Jobs wait for next day | Closed | Spend is bounded |
| spend-meter denies | No call; wait or pause | Closed | The meter has the last word |
| Field became manual | Field dropped or accept refused | Closed | Never overwrite a person |
| Model output invalid | Library retries; then `failed` | Closed | No half-drafts |
| Credit or auth error | Type paused, notice | Closed | Retrying cannot fix it |
| Image unavailable | Text-only draft, flag recorded | Open | Facts alone may suffice |
| Unsupported claims | Draft kept, flagged | Open | A person decides |
| Fact filter adds a URL | Fact dropped, logged | Closed | Only the store's own data |
| Dependency missing | Inert, notice | Closed | No fatal on the admin |
| Lock held | Job skips | Closed | No double spend |

## 5. Public interface

Namespace `IndianOpinion\WcProductCopy`. Global functions are thin
wrappers so other code can test with `function_exists()`.

### 5.1 Functions

| Function | Returns | Notes |
|---|---|---|
| `product_copy_plan(array $selection, array $args = [])` | `Plan` | Dry run. Selection keys: `ids`, `category`, `status`, `type`, `missing`, `all`. Args: `fields`, `regenerate_owned`, `refresh_drafts`, `include_discarded`. No side effects |
| `product_copy_queue(Plan $plan)` | `int` | Queues the plan's runnable items; returns the number queued. Throws when not configured |
| `product_copy_generate_now(int $product_id, array $args = [])` | `Draft` | Runs one product in the current request, same checks and caps. For CLI and tests |
| `product_copy_get_draft(int $product_id)` | `?Draft` | |
| `product_copy_accept(int $product_id, string $field, array $args = [])` | `AcceptResult` | `field` is `description`, `short` or `both`. Args: `text` (edited text), `accept_stale`. Checks capability of the current user |
| `product_copy_discard(int $product_id)` | `bool` | |
| `product_copy_undo(int $product_id, string $field)` | `AcceptResult` | |
| `product_copy_field_state(int $product_id, string $field)` | `string` | `empty`, `owned` or `manual` |
| `product_copy_is_enabled()` | `bool` | Fresh read of the switch |
| `product_copy_usage_today()` | `array` | `calls`, `call_cap`, `cost`, `cost_cap`, `cost_unknown_calls` |

### 5.2 Objects

- `Plan`: list of `PlanItem` (`productId`, `title`, `fields`, `decision`
  `run` or `skip`, `reason`, `factCount`, `image`, `estTokens`), totals,
  cap headroom. `toArray()`.
- `Draft`: `productId`, `state` (`pending`, `accepted`, `discarded`,
  `failed`), `fields` (field to text), `flags` (list of flag code and
  detail), `note`, `usedFacts`, `facts`, `inputHash`, `promptVersion`,
  `promptHash`, `model`, `tokensIn`, `tokensOut`, `cost`, `costSource`,
  `createdAt`, `decidedBy`, `decidedAt`, `isStale()`.
- `AcceptResult`: `ok` (bool), `reason` (`ok`, `manual_text`, `stale`,
  `changed`, `no_draft`, `forbidden`), `fields` written.
- Exceptions, all extending `ProductCopyException`: `NotConfiguredException`,
  `DisabledException`, `CapReachedException`, `SelectionException`,
  `GenerationException`.

### 5.3 Actions

| Action | Arguments |
|---|---|
| `product_copy_loaded` | none |
| `product_copy_queued` | `int $product_id, string $job_ref` |
| `product_copy_skipped` | `int $product_id, string $reason` |
| `product_copy_draft_saved` | `int $product_id, Draft $draft` |
| `product_copy_failed` | `int $product_id, \Throwable $error` |
| `product_copy_accepted` | `int $product_id, array $fields, int $user_id` |
| `product_copy_discarded` | `int $product_id, int $user_id` |
| `product_copy_undone` | `int $product_id, string $field, int $user_id` |
| `product_copy_cap_reached` | `string $which` (`calls` or `cost`), `string $day` |
| `product_copy_paused` | `string $reason` |

### 5.4 Filters

| Filter | Default | Purpose |
|---|---|---|
| `product_copy_facts` | built list | Add or remove facts (store data only) |
| `product_copy_brand_taxonomies` | `['product_brand']` | Where brands live |
| `product_copy_prompt` | rendered prompt | Replace system and user text |
| `product_copy_image_max_side` | 1024 | Image resize |
| `product_copy_allowed_html` | case 41 list | Tags allowed in drafts |
| `product_copy_blocked_phrases` | settings list | Phrases to flag |
| `product_copy_flags` | computed flags | Add a site check |
| `product_copy_eligible` | computed bool | Veto a product; cannot make a manual field eligible |
| `product_copy_client` | built client | Supply a configured `llm-json-php` client |
| `product_copy_capability_manage` | `manage_woocommerce` | Settings and bulk runs |
| `product_copy_retention_days` | 90 | Draft retention |

### 5.5 WP-CLI

| Command | Effect |
|---|---|
| `wp product-copy generate [<id>...] [--category=<slug>] [--status=<s>] [--type=<t>] [--missing=description\|short\|both\|any] [--fields=description\|short\|both] [--all] [--limit=<n>] [--regenerate-owned] [--refresh-drafts] [--include-discarded] [--dry-run] [--show-prompt=<id>] [--now] [--yes] [--format=table\|json\|csv]` | Plan, confirm, queue. `--now` runs in the foreground instead of queueing, still capped |
| `wp product-copy drafts [--state=<s>] [--flagged] [--format=...]` | List drafts |
| `wp product-copy show <id>` | One draft with flags and facts |
| `wp product-copy accept <id>... [--field=<f>] [--accept-stale]` | Accept, as the `--user` given; refuses without a user who can edit the product |
| `wp product-copy discard <id>...` | Discard |
| `wp product-copy status` | Switch, caps, usage today, queued, pending, failed |
| `wp product-copy pause` / `resume` | Kill switch |

Exit codes: 0 success, 1 error, 2 nothing to do, 3 paused, 4 cap reached.

### 5.6 Constants

| Constant | Effect |
|---|---|
| `PRODUCT_COPY_DISABLED` | true forces the switch off; the option cannot turn it on |
| `PRODUCT_COPY_API_KEY` | API key; preferred over the option |
| `PRODUCT_COPY_DAILY_CALL_CAP` | Overrides the option; can only lower, never raise above the option |
| `PRODUCT_COPY_DAILY_COST_CAP` | Overrides the option (decimal string) |

## 6. Data

| What | Where | Retention | Uninstall |
|---|---|---|---|
| Settings | option `product_copy_settings`, autoloaded | until changed | deleted |
| API key (if not a constant) | option `product_copy_api_key`, not autoloaded | until changed | deleted |
| Switch | option `product_copy_enabled`, not autoloaded | until changed | deleted |
| Daily usage | option `product_copy_usage`, not autoloaded, one row per day for 31 days | 31 days | deleted |
| Event log | option `product_copy_log`, not autoloaded, ring buffer of 200 events, no product text | rolling | deleted |
| Draft record | post meta `_product_copy_draft` (JSON) | case 57 | deleted |
| Owned hashes | post meta `_product_copy_owned` | while field unchanged | deleted |
| Undo values | post meta `_product_copy_undo` | until next accept | deleted |
| Writer notes | post meta `_product_copy_notes` | until cleared | deleted |
| Exclude flag | post meta `_product_copy_exclude` | until cleared | deleted |
| Locks | transients `product_copy_lock_<id>` | 10 minutes | deleted |
| Jobs | gen-queue or Action Scheduler group `product-copy` | the runner's own | pending actions unscheduled; gen-queue jobs of the type cancelled |
| Accepted descriptions | product content and excerpt | store content | kept |

Meta keys start with `_` so they are hidden from the custom fields box. No
custom tables.

## 7. Configuration and sensible defaults

| Setting | Default | Notes |
|---|---|---|
| Enabled | off after install | The owner turns it on after configuring |
| Provider, model, image support flag | none | Any route `llm-json-php` supports |
| Daily call cap | 50 | 1 to 10,000 |
| Daily cost cap | off | Decimal in the operator's currency label |
| Assumed cost per call | none | Required when the cost cap is set |
| Price table | none | Optional, per model |
| Fields | both | |
| Language | site locale | Language name sent to the prompt |
| Tone note | empty | Up to 300 characters, the operator's own |
| Description length | 80 to 300 words | |
| Short length | 15 to 60 words | |
| Minimum facts | 2 | Case 8 |
| Send image | on | Off when the route has no image support |
| Per-image token estimate | 1,000 | Dry run only |
| Include SKU and codes | off | |
| Include weight and dimensions | on | |
| Visible attributes only | on | |
| Blocked phrases | case 42 list | One per line |
| Output token cap | 1,500 | Passed to the library |
| Retention | 90 days | |

## 8. Security and privacy

- Settings and bulk runs require `manage_woocommerce`. Accept, discard,
  undo and the writer notes require `edit_product` on that product. Every
  form and button uses a nonce. Every input is validated; every output in
  admin is escaped.
- The API key is never printed back. The settings field shows whether a
  key is set. A constant key wins over the option.
- Model output is untrusted. It is cleaned with `wp_kses` (case 41) before
  it is stored as a draft and again before it is written to the product.
  It is shown in a text area, never rendered as raw HTML in admin without
  escaping.
- What leaves the site: the title, the fact list and, when enabled, a
  resized and metadata-stripped copy of one product image. The settings
  screen says so in one sentence. No customer, order or review data ever
  leaves.
- The image fetch (case 11) is limited to the site's own hosts, refuses
  redirects to other hosts, and uses the WordPress HTTP API with a timeout
  and size limit.
- No `eval`, no shell, no `@`. The log stores ids, reasons and counts,
  never product text or prompts.

## 9. Performance budget

- Front end: zero queries, zero scripts, zero styles added.
- Admin: the meta box and drafts screen load their assets only on those
  screens. The drafts screen pages at 20 rows.
- A dry run over 1,000 products completes in under 30 seconds on a typical
  host, using under 64 MB of memory above WordPress's baseline.
- One job: one model call, one image resize at most, a handful of meta
  reads and writes. The image resize is done once per job and not kept.
- Housekeeping runs once a day and processes at most 500 products per run.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 or later. WooCommerce 8.0 or later.
- Declares compatibility with WooCommerce's order storage feature (the
  plugin never touches orders).
- Classic product edit screen: full meta box. The block-based product
  editor: the drafts screen works; the meta box may not show (open
  question Q3).
- Persistent object caches: the switch and caps are read through the
  options API at the start of each job (case 29).
- Offloaded uploads: case 11.
- Multisite: case 63.
- Works with or without gen-queue, spend-meter and prompt-library.

## 11. Observability

- `wp product-copy status` and a status panel at the top of the drafts
  screen: switch, caps and usage today, queued, pending, flagged, failed,
  and the runner in use.
- The event log (last 200 events): queued, skipped with reason, saved,
  failed with exception class, accepted, discarded, paused, cap reached.
  Shown on the settings screen.
- Every event also fires an action (section 5.3), so a site can forward
  them to its own logging.
- When a cap is reached or the type is paused, users who can manage the
  plugin see one admin notice on product screens until the next day or
  until resumed.

## 12. Acceptance tests

Each test names its case. Tests run on PHPUnit with a stub layer for
WordPress and WooCommerce and the `llm-json-php` mock transport.

1. (1) Fact list order is notes, brand, type, categories, attributes,
   dimensions, tags; title is separate.
2. (2) Taxonomy attribute sends term names; hidden attribute excluded;
   variation attribute appears as "available in".
3. (3) Three lines of writer notes give three facts.
4. (4) SKU absent by default, present when enabled; weight present.
5. (5) A filter that adds price, a URL and an email: price key never sent,
   URL and email facts dropped and logged.
6. (6) A 500-character multibyte value is cut to 200 characters with valid
   UTF-8; duplicates dropped; the 61st fact dropped and counted.
7. (7) Filter adds a fact; it appears in the prompt.
8. (8) Title plus one fact, no image: skipped `insufficient_data`, no call.
9. (9) No featured image: first gallery image used; image off in settings:
   none sent.
10. (10) A 4,000 pixel image is sent at 1,024 pixels, as JPEG, with no EXIF.
11. (11) Offloaded file on the site's host is fetched; on another host it
    is not, and `image_unavailable` is recorded; a redirect off-host is
    refused.
12. (12) SVG featured image: no image sent.
13. (13) Empty field eligible; owned field eligible only with
    `regenerate_owned`.
14. (14) Manual description: skipped `manual_text`, never written.
15. (15) Manual description, empty short: prompt asks for short only.
16. (16) Variation and trashed product never processed; draft product is.
17. (17) Excluded product skipped `excluded`.
18. (18) Pending draft with same hash: `draft_current`; different hash:
    `draft_pending`; with refresh: replaced.
19. (19) Discarded draft skipped unless `include_discarded`.
20. (20) Changing the tone note changes the input hash; changing the price
    does not.
21. (21) Bulk action opens the review screen and queues nothing until the
    button with a valid nonce.
22. (22) `generate` with no selection exits 1.
23. (23) Dry run: mock transport receives zero requests; no meta, queue or
    counter change; report totals correct.
24. (24) With 3 calls left, a 10-product dry run says 3 today and 7 later.
25. (25) `--show-prompt` prints the prompt with the image placeholder and
    makes no call.
26. (26) Without `--yes` the command asks; with it, it queues.
27. (27) 1,200 products are selected in pages and queued in three batches.
28. (28) Queueing the same selection twice queues nothing new.
29. (29) Switch turned off between two jobs of one batch: the second job
    makes no call.
30. (30) Switch off: job waits, queueing still works, notice shown;
    constant true overrides the option.
31. (31) Call cap 2 with a billed failure and a success: the third job
    waits; cap counts attempts.
32. (32) Cost cap with unknown cost uses the assumed cost; settings refuse
    a cost cap without it.
33. (33) spend-meter stub denying with `cap_site_day`: no call, job waits;
    with `kill_switch`: type paused; allowed: record fired with the
    reservation id; reserved then not called: release fired.
34. (34) Two workers on one product: one call.
35. (35) Description filled by hand after queueing: job drops it; no field
    left means no call.
36. (36) Product trashed after queueing: reason `gone`, no call.
37. (37) With a prompt-library stub the template is rendered; without, the
    built-in prompt; the filter overrides both.
38. (38) Built-in prompt contains the instructions in case 38 and the tone
    note when set.
39. (39) Request sends the schema and, with an image, one image part.
40. (40) Answer missing the requested field fails the attempt.
41. (41) Markdown bullets become a list; a script tag, a link and a
    heading are removed or converted.
42. (42) Each flag in case 42 fires on a crafted answer and lists details.
43. (43) Word limits flag `too_long` and `thin`; a `ja` locale counts
    characters with doubled limits.
44. (44) Normalisation: entity and whitespace variants hash the same.
45. (45) Saving a draft leaves content, excerpt, status and modified date
    unchanged.
46. (46) Exhausted retries give state `failed` without raw output; a
    credit error pauses the type and shows the notice.
47. (47) Switch off while a call is in flight: draft saved, next job does
    not call.
48. (48) Meta box renders current value, draft, flags and buttons, all
    escaped.
49. (49) Changed attribute marks the draft stale; accept needs
    `accept_stale`.
50. (50) Bulk accept takes clean drafts only and reports skipped rows.
51. (51) Field filled by hand after drafting: accept refused
    `manual_text`, draft still pending, copy box shown.
52. (52) Accept saves through the product object, keeps status, stores the
    owned hash and undo value.
53. (53) Edited draft with a script tag is cleaned before saving; owned
    hash matches the saved text.
54. (54) Editing an accepted field makes it manual.
55. (55) Undo restores the previous value; after a manual edit undo is
    refused `changed`.
56. (56) Discard keeps the record; single regenerate ignores case 19 and
    respects caps.
57. (57) Housekeeping strips text from 91-day-old records and keeps state.
58. (58) With a gen-queue stub: type registered, jobs enqueued with the
    idempotency key, `gen_queue_can_run` vetoes while capped.
59. (59) Without gen-queue: Action Scheduler stub receives one action per
    product; a capped job is rescheduled for next day.
60. (60) Library classes missing: notice, no fatal.
61. (61) WooCommerce inactive: notice, no fatal.
62. (62) No key: dry run works, queue refused `not_configured`.
63. (63) Two sites: separate caps and drafts.
64. (64) Uninstall removes options, meta, transients and scheduled
    actions, and leaves accepted descriptions.

## 13. Open questions

Answered by the builder in `docs/ARCHITECTURE.md`.

- Q1. Does `llm-json-php` pass an image content part in a caller's message
  list through unchanged on every provider kind? If not, how is the image
  sent (a small extension upstream, or text-only until it is)?
- Q2. Should variation descriptions be in scope for 0.1.0, or stay a
  later feature?
- Q3. How should drafts appear in the block-based product editor: a
  sidebar panel, or the drafts screen only?
- Q4. Is strict refusal over a manual field (case 51) right, or should a
  second, explicit "replace and keep a backup" path exist for editors?
- Q5. Are the defaults right: call cap 50, cost cap off, plugin disabled
  after install? Should a cost cap be required before the first run?
- Q6. Should image alt text be a third optional field, given the image is
  already sent?
- Q7. Stores with more than one language: one draft per language, or out
  of scope?

## 14. Lessons from production

Written as rules. They come from general WordPress, WooCommerce and
language model practice.

1. Check "is this field a person's text?" twice: when choosing work, and
   again at the moment of writing. Text changes between the two.
2. Decide ownership by a hash of what was actually saved, read back after
   save. Editors and filters reformat text; hashing what was sent breaks.
3. Write products through the WooCommerce product object, never with
   direct post updates. Direct updates skip caches, lookup data and hooks.
4. Read the kill switch and caps at the start of every job, not once per
   batch. A switch that takes effect only after the batch is not a switch.
5. Count billed failures against the cap. A failed call still costs money.
6. Leave headroom in the output token cap. Hidden reasoning tokens count
   as output on some models and can leave the visible answer truncated.
7. Treat model output as untrusted HTML. Clean it before storing and again
   before writing. Models return Markdown, links and stray tags.
8. Never send prices, stock or delivery terms to the model. Copy that
   states them is wrong as soon as they change.
9. Send images inline, resized and re-encoded. Public links fail on
   private stores and leak metadata.
10. Use term names, not slugs. A slug like `col-blu-2` becomes nonsense in
    copy.
11. Decode entities before sending text and before counting words.
    `&amp;` in a title becomes `amp` in a description.
12. Do not write copy from a title alone. Thin input gives invented
    output; skip it and say why.
13. Never trust WP-Cron alone on a quiet site. Use a real runner (the
    queue or Action Scheduler) and document a system cron line.
14. A dry run must not spend, write or count. Test that with a transport
    that fails on any request.
15. Make queueing idempotent. People press the button twice.

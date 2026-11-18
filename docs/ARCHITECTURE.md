# Architecture

How wc-product-copy is put together, how data moves through it, and every
decision taken while building it from [PRD.md](PRD.md). Case numbers refer
to section 4 of the PRD.

## Modules

| Module | Files | Job |
|---|---|---|
| Entry | `wc-product-copy.php`, `autoload.php`, `src/Plugin.php` | Loads classes (Composer autoloader or a PSR-4 fallback), checks WooCommerce and a caller, builds the services once per request, registers hooks. Inert with a notice when a dependency is missing (cases 60, 61). |
| Settings | `src/Settings.php`, `src/SettingsRepository.php`, `src/PriceTableText.php`, `src/Credentials.php` | Validated settings value object; the API key on its own (constant first). |
| Switch and counters | `src/KillSwitch.php`, `src/Usage.php`, `src/Log.php`, `src/Clock.php` | Kill switch with a pause reason, daily usage rows, the 200-event log, the site day. |
| Facts | `src/Facts/*` | Builds the numbered fact list from the store's own data (cases 1 to 8). |
| Image | `src/Image/*` | Picks the image, fetches it from the site's own hosts if offloaded, resizes, re-encodes and strips metadata (cases 9 to 12). |
| Planning | `src/Planner.php`, `src/Plan.php`, `src/PlanItem.php`, `src/InputHash.php`, `src/Fields.php` | Selection, field states, eligibility, input hash, the dry run (cases 13 to 28). |
| Prompt | `src/Prompt/*` | Built-in prompt, prompt-library rendering, the filter, the JSON schema (cases 37 to 39). |
| Caller | `src/Caller/*`, `src/CallerFactory.php` | The `ModelCaller` seam and the llm-json-php reference adapter. |
| Generation | `src/Generator.php`, `src/JobOutcome.php`, `src/Lock.php`, `src/SpendMeter.php`, `src/HtmlCleaner.php`, `src/FactCheck.php`, `src/Draft.php`, `src/DraftStore.php` | One job, cases 29 to 47. |
| Runners | `src/Queue/*`, `src/Queuer.php` | gen-queue when present, else Action Scheduler (cases 58, 59); batching and idempotency (cases 27, 28). |
| Review | `src/Review/*` | Accept, replace, discard, undo, bulk accept (cases 49 to 56). |
| Housekeeping | `src/Housekeeping.php`, `src/Uninstaller.php` | Retention (case 57) and uninstall (case 64). |
| Admin | `src/Admin/*` | Meta box, drafts screen, review screen, settings page, notices, form handlers. |
| CLI | `src/Cli/Command.php` | `wp product-copy`. |
| Functions | `src/functions.php` | The global functions of PRD section 5.1. |

## Data flow

```
products list bulk action ──► review screen (dry run) ──► "Queue N" (nonce, capability)
wp product-copy generate ──► Planner::plan() (dry run) ──► confirm ──► Queuer::queue()
                                                                 │
                       one job per product, idempotency key ◄────┘
                                     │
          gen-queue handler  or  Action Scheduler action (group product-copy)
                                     │
                                Generator::run()
  switch (fresh) ─► product gone? ─► lock ─► Planner::evaluate() again ─► caller configured?
  ─► call cap ─► cost cap ─► image ─► spend-meter pre-spend ─► count 1 call ─► ModelCaller::call()
  ─► count extra billed attempts, record cost, spend-meter record
  ─► HtmlCleaner ─► FactCheck ─► product_copy_flags ─► Draft (post meta only)
                                     │
             meta box / drafts screen / wp product-copy accept
                                     │
  Acceptor: nonce + edit_product ─► re-read field (manual? refuse) ─► clean again
  ─► WC_Product::set_*() + save() ─► read back ─► owned hash + undo value
```

The same `Planner::evaluate()` decides a product in the dry run and again at
the start of every job, so the rules cannot drift apart.

## Storage

Everything in PRD section 6, plus three additions recorded as decisions
below: the post meta `_product_copy_purge_after`, the transient prefix
`product_copy_llm` for llm-json-php's cooldown store, and the runner lock
`product_copy_lock_runner`. No custom tables.

## Rulings on the open questions

**Q1. Image parts through llm-json-php.** llm-json-php 0.1 accepts string
message content only (`Request::withMessages()` rejects arrays), so it
cannot carry an image part. Ruling: image input is optional; the reference
adapter `LlmJsonCaller` is text-only and reports `supportsImages() = false`.
The image is then not prepared at all and the draft record says
`image: {status: not_sent, detail: caller_text_only}`. A site that needs
images supplies its own `ModelCaller` through `product_copy_caller`; the
plugin then resizes, strips and sends the image as one `image_url` part
(`ModelRequest::messages()`). The dry run and case 8 count an image only when
the caller can send it, so a text-only setup never runs on a title plus one
fact.

**Q2. Variation descriptions.** Out of scope. Variations are never
processed (`wrong_type`).

**Q3. Block-based product editor.** Drafts screen only; no product-editor
panel. The classic meta box is unchanged; on the block editor, editors use
Products > Copy drafts and the CLI.

**Q4. Manual fields.** Strict refusal stays the default: accept, bulk accept
and the CLI never overwrite a manual field (`manual_text`). A per-product
"Replace" button exists in the meta box only, needs its own confirmation
tick, and keeps the previous value twice: in the draft record
(`extra.replaced`) and in the undo meta, so "Undo accept" restores it.

**Q5. Defaults.** Kept: call cap 50, cost cap off, plugin off after install.
No cost cap is required before the first run.

**Q6. Image alt text.** Not a field. Out of scope.

**Q7. Multi-language stores.** Out of scope. One language per site, from the
setting or the site locale.

## Decisions taken by the builder

1. **Caller seam.** Every model call goes through the `ModelCaller`
   interface. The filter `product_copy_caller` lets a site plug in its own
   caller and run without llm-json-php (the group C ruling). The PRD's
   `product_copy_client` filter still lets a site hand in a configured
   llm-json-php `Client`. The plugin is inert only when the library is not
   loaded and no caller filter is registered by `plugins_loaded` priority 20.
2. **Schema per request.** Requested fields are `{"type": "string",
   "minLength": 1}`; fields not requested are `["string", "null"]`. A
   custom guard rule also rejects whitespace-only or tag-only text. Both make
   llm-json-php treat a missing field as invalid output and apply its own
   retry (case 40). The generator checks again for callers that do not
   enforce the schema.
3. **Counting calls.** One call is counted before the request (attempt
   first, so a crash cannot loop unpaid). After the call, every further
   attempt that got an HTTP status back is added, because the library may
   retry inside one job. Transport errors with no response are not billed.
4. **Unknown cost.** A call without a provider or price-table cost is
   recorded at the assumed cost per call and counted in
   `cost_unknown_calls`. With no assumed cost (cost cap off) it is recorded
   as 0 and still counted as unknown.
5. **Extra skip reasons.** Besides the PRD's reasons: `owned_text` (every
   requested field is owned and regeneration was not asked for), `wrong_type`
   (variations and unknown types), `wrong_status`, `vetoed` (the
   `product_copy_eligible` filter said no) and `gone` (also used in the dry
   run). The eligible filter must return exactly `true` to keep a product,
   and it runs after the manual check, so it cannot make a manual field
   eligible.
6. **Variation attributes.** On a variable product, attributes used for
   variations are sent ("available in: ...") even when not marked visible,
   because shoppers see them in the option picker.
7. **Normalisation (case 44).** Besides decoding, line endings and
   collapsing, whitespace next to a block tag (`p`, `br`, lists, headings,
   `div`) is dropped before hashing. Editors add and remove it freely, and
   without this an untouched field could turn "manual" after a round trip.
8. **HTML clean-up.** A DOM pass runs before `wp_kses()`: script, style,
   iframe, media and form elements are removed with their content (kses
   alone would keep the text of a script); headings become paragraphs unless
   the allowed list names them; links and every other tag not on the list
   are unwrapped; attributes are removed; URLs, email addresses and
   phone-like strings (9 to 15 digits, year ranges excluded) are cut from
   text and reported as `contact_removed`. The `product_copy_allowed_html`
   filter can add tags but never `a`, never a dropped element, and never
   attributes.
9. **Locks.** Transient `product_copy_lock_<id>`. Without a persistent
   object cache the transient's option row is taken with one
   `INSERT IGNORE`, so of two racing workers exactly one wins; an expired
   lock is cleared by `get_transient()` first. With a persistent object
   cache, `wp_cache_add()` is used, which is atomic. The Action Scheduler
   runner also takes `product_copy_lock_runner` so at most one job runs at a
   time; a second one is moved 60 seconds later. The daily usage option is
   changed under a short lock (`product_copy_lock_usage`, 30 seconds), and
   the call cap and cost cap are checked and the attempt counted in that
   same locked step, so two workers cannot both take the last call.
10. **Action Scheduler retries.** Three attempts per job, backoff 5 then 10
    minutes. Cap and meter waits go to the start of the next site day; switch
    and pause waits are checked again hourly. A failed draft record is written
    only on the last attempt.
11. **gen-queue.** Checked against gen-queue 0.1.0's PRD and README:
    `gen_queue_register_types`, `gen_queue_register_type()` (args
    `resource_key`, `group`, `cost_estimate`, `timeout`, `max_attempts`),
    `gen_queue_enqueue()` with `idempotency_key`, the `gen_queue_can_run`
    filter `(bool $allowed, Job $job)`, `gen_queue_cancel_where(['type' =>
    ...])`, and the `JobResult`, `RetryableException`, `PermanentException`
    and `AccountException` classes, all looked up by name and never
    required. Group and resource key are cut to gen-queue's label rules
    (64 and 128 bytes); a registration error is caught and logged so it
    can never break `init`. A job counts as newly queued when
    `gen_queue_enqueued` fired during the enqueue call. Cap waits throw
    `RetryableException` with `retryAfter` set to the start of the next
    site day, switch waits with one hour; the `gen_queue_can_run` veto
    normally stops them before an attempt is used. Credit and meter
    kill-switch problems throw `AccountException`, which requeues without
    using an attempt and holds the group. Costs go to `JobContext::addCost()`
    with integer `input_units` and `output_units` only when known, and
    `metered: true` when spend-meter already recorded them. The job's
    `attempts` and `maxAttempts` decide the final attempt.
12. **prompt-library.** Checked against prompt-library 0.1.0 (contract
    1.0), whose public names use the prefix `promptlib_`. Rendering applies
    the soft filter `promptlib_render` with `(null, 'product-copy-generate',
    $values, 'live', ['consumer' => 'wc-product-copy'])`; it returns `null`
    when the library is inactive, an array with `system`, `user`,
    `version` and `content_hash`, or a `WP_Error`, which falls back to the
    built-in prompt and is logged as `prompt_fallback`. On `init` the plugin
    calls `promptlib_register_default('wc-product-copy', $definition)` with
    a `prompt-library/1` definition of the built-in prompt (its templates
    use the library's `{{ name }}` syntax) and
    `promptlib_register_consumer()` with the variable names. The library
    fences `title` and `facts` with its `fenced` escape; the built-in
    renderer fences them the same way.
13. **spend-meter.** Checked against spend-meter 0.1.0 (contract 1). The
    pre-spend intent holds `purpose`, `caller`, `provider`, `model`,
    `est_input_tokens` and `max_output_tokens`; the answer is read for
    `allowed`, `reason` and `reservation_id`. Only `allowed === true` lets a
    call through. Any reason starting `kill_switch` pauses the plugin. A
    denial with a reservation id, or a cap reached after an allow, fires
    `spend_meter_release`. The record carries integer token counts and,
    only when the provider reported it, `provider_cost` in nanos.
14. **Failures never overwrite a pending draft.** If a refresh run fails, the
    editor's pending draft stays and only the log records the failure.
15. **The dry run writes nothing at all.** The event log is muted while a
    plan is built, so even a fact dropped by the filter is logged only when a
    real job meets it.
16. **Housekeeping index.** Each saved draft also gets
    `_product_copy_purge_after` (a Unix time). The daily event queries that
    key with a numeric comparison, at most 500 products, and deletes it after
    stripping, so old records are not scanned again.
17. **Library store.** llm-json-php's transient store uses the key prefix
    `product_copy_llm` and per-site transients, so cooldowns are per site
    (case 63) and uninstall can remove them without touching other plugins.
18. **Batches.** Queueing fires `product_copy_batch_queued` (batch number,
    count) after each batch of 500. It is an addition to PRD section 5.3.
19. **Partial accept.** Accepting one field of a two-field draft leaves the
    draft pending for the other; the state becomes `accepted` when every
    drafted field is accepted. A single accept does not look at flags; bulk
    accept skips flagged drafts (case 50).
20. **Discard** works on pending and failed drafts.
21. **Not configured at run time.** If the key or model disappears after
    queueing, jobs wait (hourly recheck) instead of failing.
22. **Kill switch option shape.** `product_copy_enabled` holds
    `{enabled, paused, paused_at}`. A credit, authentication or meter
    kill-switch problem sets `paused`; "Resume" (or `wp product-copy resume`)
    turns the switch on and clears the pause.
23. **Meta box forms.** The meta box's buttons and text areas belong to a
    separate form printed in the admin footer (HTML `form` attribute), so
    they never submit or nest inside the product form. Writer notes and the
    exclude box save with the product under their own nonce.
24. **Admin results.** Form handlers redirect with short result codes in the
    query string; notices map them to text. Nothing user-supplied is echoed.
25. **Plugin name.** The plugin header says "Product Copy Drafts"; the slug
    and text domain stay `wc-product-copy`.
26. **Composer platform.** `config.platform.php` is 8.1 so dependencies
    resolve for the lowest supported PHP.
27. **Tests.** A stub layer in `tests/stubs/` imitates the WordPress and
    WooCommerce functions the plugin calls, with in-memory options, meta,
    transients, Action Scheduler actions and per-site data. llm-json-php's
    `MockTransport` and a fake caller stand in for the model; a stub
    `wp_remote_post()` throws, so a test cannot reach the network. Tests that
    need a constant or a companion plugin run in a separate process.
28. **Store data in the prompt.** The title and the facts come from
    data anyone who can edit a product controls (attributes, tags, writer
    notes). Both are fenced in `<title>` and `<facts>` blocks, a closing tag
    inside a value becomes `[/title]` or `[/facts]`, and the system text says
    that fenced text is data, never instructions. Fencing lowers the risk of
    prompt injection; it does not remove it, which is why every draft is
    cleaned, fact-checked and accepted by a person.
29. **Partial accept is final per field.** A field accepted from a draft
    is never written again from that draft, so its undo value is the text
    from before the first accept. Replacing a manual field needs
    `replace` to be exactly `true`.

## Performance

Measured on the stub layer (no database): a dry run over 1,000 products
took 1.1 seconds with 4.4 MB peak memory above the starting point. A real
store adds its own query time per product (one `wc_get_product()`, a few
meta and term reads). Selection pages ids 200 at a time and keeps only small
plan items in memory.

## Not done in 0.1.0

- No panel in the block-based product editor (ruling Q3).
- The reference adapter cannot send images (ruling Q1).
- The drafts screen's "stale" filter checks at most 500 pending drafts per
  page view, because staleness is computed per product.

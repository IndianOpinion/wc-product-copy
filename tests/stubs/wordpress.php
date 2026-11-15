<?php

/**
 * A small stub layer for the WordPress functions this plugin uses. Enough
 * behaviour to test the plugin without a WordPress install; not a copy of
 * WordPress.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

class WP_Error
{
    public function __construct(public string $code = 'error', public string $message = '')
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

class WP_Term
{
    public int $term_id;
    public string $name;
    public string $slug;
    public int $parent;
    public string $taxonomy;

    public function __construct(int $id, string $name, string $taxonomy, int $parent = 0, ?string $slug = null)
    {
        $this->term_id = $id;
        $this->name = $name;
        $this->taxonomy = $taxonomy;
        $this->parent = $parent;
        $this->slug = $slug ?? strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));
    }
}

class WP_Post
{
    public int $ID;
    public string $post_type = 'product';
    public string $post_status = 'publish';
    public string $post_title = '';

    public function __construct(int $id)
    {
        $this->ID = $id;
    }
}

class WP_Query
{
    /** @var list<int> */
    public array $posts = [];
    public int $found_posts = 0;

    /**
     * @param array<string, mixed> $args
     */
    public function __construct(array $args = [])
    {
        $site = &WpState::site();
        $ids = [];
        foreach ($site['posts'] as $id => $post) {
            if (($args['post_type'] ?? 'post') !== $post['type']) {
                continue;
            }
            $status = $args['post_status'] ?? 'publish';
            if ($status !== 'any' && $post['status'] !== $status) {
                continue;
            }
            if ($status === 'any' && in_array($post['status'], ['trash', 'auto-draft'], true)) {
                continue;
            }
            if (!self::metaMatches((int) $id, $args['meta_query'] ?? [])) {
                continue;
            }
            if (isset($args['meta_key']) && !isset($site['meta'][$id][$args['meta_key']])) {
                continue;
            }
            if (isset($args['tax_query'][0])) {
                $tax = $args['tax_query'][0];
                $slugs = array_map(static fn (WP_Term $t): string => $t->slug, $site['object_terms'][$id][$tax['taxonomy']] ?? []);
                if (array_intersect($slugs, (array) $tax['terms']) === []) {
                    continue;
                }
            }
            $ids[] = (int) $id;
        }
        sort($ids);
        $this->found_posts = count($ids);
        $per = (int) ($args['posts_per_page'] ?? 10);
        $page = max(1, (int) ($args['paged'] ?? 1));
        $this->posts = $per < 0 ? $ids : array_values(array_slice($ids, ($page - 1) * $per, $per));
    }

    /**
     * @param array<mixed> $query
     */
    private static function metaMatches(int $id, array $query): bool
    {
        $site = &WpState::site();
        foreach ($query as $key => $clause) {
            if ($key === 'relation' || !is_array($clause)) {
                continue;
            }
            $value = $site['meta'][$id][$clause['key']] ?? null;
            $compare = $clause['compare'] ?? '=';
            if ($compare === 'EXISTS') {
                if ($value === null) {
                    return false;
                }
                continue;
            }
            if ($value === null) {
                return false;
            }
            if ($compare === 'LIKE') {
                if (!is_string($value) || !str_contains($value, (string) $clause['value'])) {
                    return false;
                }
            } elseif ($compare === '<=') {
                if (!((float) $value <= (float) $clause['value'])) {
                    return false;
                }
            } elseif ((string) $value !== (string) $clause['value']) {
                return false;
            }
        }

        return true;
    }
}

final class WpdbStub
{
    public string $options = 'wp_options';
    /** @var list<mixed> */
    private array $lastArgs = [];

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $this->lastArgs = $args;

        return $query;
    }

    /**
     * @return list<string>
     */
    public function get_col(string $query): array
    {
        $site = &WpState::site();
        $out = [];
        foreach (array_keys($site['options']) as $name) {
            foreach ($this->lastArgs as $pattern) {
                if (preg_match(self::likeToRegex((string) $pattern), (string) $name) === 1) {
                    $out[] = (string) $name;
                    break;
                }
            }
        }

        return $out;
    }

    private static function likeToRegex(string $like): string
    {
        $regex = '';
        $length = strlen($like);
        for ($i = 0; $i < $length; $i++) {
            $c = $like[$i];
            if ($c === '\\' && $i + 1 < $length) {
                $regex .= preg_quote($like[++$i], '/');
            } elseif ($c === '%') {
                $regex .= '.*';
            } elseif ($c === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($c, '/');
            }
        }

        return '/^' . $regex . '$/s';
    }
}

$GLOBALS['wpdb'] = new WpdbStub();

// Hooks.
function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool
{
    WpState::$hooks[$hook][$priority][] = [$callback, $accepted];
    ksort(WpState::$hooks[$hook]);

    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool
{
    return add_filter($hook, $callback, $priority, $accepted);
}

function remove_action(string $hook, callable $callback, int $priority = 10): bool
{
    foreach (WpState::$hooks[$hook][$priority] ?? [] as $i => [$cb]) {
        if ($cb === $callback) {
            unset(WpState::$hooks[$hook][$priority][$i]);

            return true;
        }
    }

    return false;
}

function remove_all_filters(string $hook): void
{
    unset(WpState::$hooks[$hook]);
}

function has_filter(string $hook, mixed $callback = false): bool|int
{
    return !empty(WpState::$hooks[$hook]) ? 10 : false;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach (WpState::$hooks[$hook] ?? [] as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $value = $callback(...array_slice(array_merge([$value], $args), 0, max(1, $accepted)));
        }
    }

    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    WpState::$fired[] = [$hook, $args];
    foreach (WpState::$hooks[$hook] ?? [] as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $callback(...array_slice($args, 0, $accepted));
        }
    }
}

// Options and transients.
function get_option(string $name, mixed $default = false): mixed
{
    $site = &WpState::site();

    return array_key_exists($name, $site['options']) ? $site['options'][$name] : $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    $site = &WpState::site();
    $site['options'][$name] = $value;

    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
{
    $site = &WpState::site();
    if (array_key_exists($name, $site['options'])) {
        return false;
    }
    $site['options'][$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    $site = &WpState::site();
    unset($site['options'][$name]);

    return true;
}

function get_transient(string $name): mixed
{
    $site = &WpState::site();
    $timeout = $site['options']['_transient_timeout_' . $name] ?? null;
    if ($timeout !== null && $timeout < \IndianOpinion\WcProductCopy\Clock::now()) {
        delete_transient($name);

        return false;
    }

    return $site['options']['_transient_' . $name] ?? false;
}

function set_transient(string $name, mixed $value, int $expiration = 0): bool
{
    $site = &WpState::site();
    $site['options']['_transient_' . $name] = $value;
    if ($expiration > 0) {
        $site['options']['_transient_timeout_' . $name] = \IndianOpinion\WcProductCopy\Clock::now() + $expiration;
    }

    return true;
}

function delete_transient(string $name): bool
{
    $site = &WpState::site();
    unset($site['options']['_transient_' . $name], $site['options']['_transient_timeout_' . $name]);

    return true;
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    unset(WpState::$cache[$group][$key]);

    return true;
}

function wp_cache_add(string $key, mixed $value, string $group = '', int $expire = 0): bool
{
    if (isset(WpState::$cache[$group][$key])) {
        return false;
    }
    WpState::$cache[$group][$key] = $value;

    return true;
}

function wp_using_ext_object_cache(): bool
{
    return false;
}

// Post meta and posts.
function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    $site = &WpState::site();
    $value = $site['meta'][$id][$key] ?? null;
    if ($value === null) {
        return $single ? '' : [];
    }

    return $single ? $value : [$value];
}

function update_post_meta(int $id, string $key, mixed $value): bool
{
    $site = &WpState::site();
    $site['meta'][$id][$key] = wp_unslash($value);

    return true;
}

function delete_post_meta(int $id, string $key): bool
{
    $site = &WpState::site();
    unset($site['meta'][$id][$key]);

    return true;
}

function delete_post_meta_by_key(string $key): bool
{
    $site = &WpState::site();
    foreach (array_keys($site['meta']) as $id) {
        unset($site['meta'][$id][$key]);
    }

    return true;
}

function get_post_status(int $id): string|false
{
    $site = &WpState::site();

    return $site['posts'][$id]['status'] ?? false;
}

function get_the_title(int $id): string
{
    $site = &WpState::site();

    return (string) ($site['posts'][$id]['title'] ?? '');
}

/**
 * @param array<string, mixed> $args
 *
 * @return list<int>
 */
function get_posts(array $args = []): array
{
    return (new WP_Query($args))->posts;
}

function wp_is_post_revision(int $id): int|false
{
    return false;
}

// Terms.
function taxonomy_exists(string $taxonomy): bool
{
    $site = &WpState::site();

    return in_array($taxonomy, $site['taxonomies'], true);
}

function get_term(int $id, string $taxonomy = ''): WP_Term|WP_Error|null
{
    $site = &WpState::site();

    return $site['terms'][$id] ?? null;
}

/**
 * @return list<int>
 */
function get_ancestors(int $id, string $object = '', string $type = ''): array
{
    $site = &WpState::site();
    $out = [];
    $term = $site['terms'][$id] ?? null;
    while ($term instanceof WP_Term && $term->parent > 0) {
        $out[] = $term->parent;
        $term = $site['terms'][$term->parent] ?? null;
    }

    return $out;
}

/**
 * @return list<WP_Term>|false
 */
function get_the_terms(int $id, string $taxonomy): array|false
{
    $site = &WpState::site();
    $terms = $site['object_terms'][$id][$taxonomy] ?? [];

    return $terms === [] ? false : $terms;
}

// Attachments and images.
function get_attached_file(int $id): string|false
{
    $site = &WpState::site();

    return $site['attachments'][$id]['file'] ?? false;
}

function get_post_mime_type(int $id): string|false
{
    $site = &WpState::site();

    return $site['attachments'][$id]['mime'] ?? false;
}

function wp_get_attachment_url(int $id): string|false
{
    $site = &WpState::site();

    return $site['attachments'][$id]['url'] ?? false;
}

/**
 * @return array<string, mixed>|false
 */
function wp_get_attachment_metadata(int $id): array|false
{
    $site = &WpState::site();

    return $site['attachments'][$id]['meta'] ?? false;
}

function wp_get_image_editor(string $path): object
{
    return GdImageEditorStub::load($path);
}

function wp_tempnam(string $filename = ''): string
{
    $file = (string) tempnam(sys_get_temp_dir(), 'pcopy');
    WpState::$tempFiles[] = $file;
    WpState::$tempFiles[] = $file . '.jpg';

    return $file;
}

/**
 * @return array<string, mixed>
 */
function wp_upload_dir(?string $time = null, bool $create = true): array
{
    return ['path' => '/tmp', 'url' => 'https://cdn.shop.test/uploads', 'subdir' => '', 'basedir' => '/tmp', 'baseurl' => 'https://cdn.shop.test/uploads', 'error' => false];
}

final class GdImageEditorStub
{
    private \GdImage $image;
    private int $quality = 90;

    private function __construct(\GdImage $image)
    {
        $this->image = $image;
    }

    public static function load(string $path): object
    {
        $data = is_file($path) ? (string) file_get_contents($path) : '';
        $image = $data !== '' && getimagesizefromstring($data) !== false ? imagecreatefromstring($data) : false;
        if (!$image instanceof \GdImage) {
            return new WP_Error('invalid_image', 'File is not an image.');
        }

        return new self($image);
    }

    /**
     * @return array{width: int, height: int}
     */
    public function get_size(): array
    {
        return ['width' => imagesx($this->image), 'height' => imagesy($this->image)];
    }

    public function resize(int $maxW, int $maxH, bool $crop = false): bool
    {
        $w = imagesx($this->image);
        $h = imagesy($this->image);
        $ratio = min($maxW / $w, $maxH / $h);
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $new = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($new, $this->image, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $this->image = $new;

        return true;
    }

    public function set_quality(int $quality): bool
    {
        $this->quality = $quality;

        return true;
    }

    /**
     * @return array{path: string, file: string, width: int, height: int, mime-type: string}
     */
    public function save(string $path, string $mime): array
    {
        imagejpeg($this->image, $path, $this->quality);
        WpState::$tempFiles[] = $path;

        return ['path' => $path, 'file' => basename($path), 'width' => imagesx($this->image), 'height' => imagesy($this->image), 'mime-type' => 'image/jpeg'];
    }
}

// HTTP.
/**
 * @param array<string, mixed> $args
 *
 * @return array<string, mixed>|WP_Error
 */
function wp_safe_remote_get(string $url, array $args = []): array|WP_Error
{
    WpState::$httpRequests[] = ['url' => $url, 'args' => $args];
    if (WpState::$http === null) {
        throw new LogicException('Unexpected HTTP request in a test: ' . $url);
    }

    return (WpState::$http)($url, $args);
}

/**
 * @param array<string, mixed>|WP_Error $response
 */
function wp_remote_retrieve_response_code(array|WP_Error $response): int|string
{
    return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
}

/**
 * @param array<string, mixed>|WP_Error $response
 *
 * @return array<string>|string
 */
function wp_remote_retrieve_header(array|WP_Error $response, string $header): array|string
{
    if (!is_array($response)) {
        return '';
    }
    foreach ((array) ($response['headers'] ?? []) as $name => $value) {
        if (strtolower((string) $name) === strtolower($header)) {
            return $value;
        }
    }

    return '';
}

/**
 * @param array<string, mixed>|WP_Error $response
 */
function wp_remote_retrieve_body(array|WP_Error $response): string
{
    return is_array($response) ? (string) ($response['body'] ?? '') : '';
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

// URLs, users, nonces.
function home_url(string $path = ''): string
{
    return 'https://shop.test' . $path;
}

function site_url(string $path = ''): string
{
    return 'https://shop.test' . $path;
}

function admin_url(string $path = ''): string
{
    return 'https://shop.test/wp-admin/' . $path;
}

/**
 * @param array<string, mixed>|string $args
 */
function add_query_arg(array|string $args, string $url = ''): string
{
    $sep = str_contains($url, '?') ? '&' : '?';

    return $url . $sep . http_build_query((array) $args);
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

function get_current_user_id(): int
{
    return WpState::$user;
}

function current_user_can(string $capability, mixed ...$args): bool
{
    if (WpState::$user === 0) {
        return false;
    }
    if (isset($args[0]) && in_array((int) $args[0], WpState::$deniedProducts, true)) {
        return false;
    }

    return in_array($capability, WpState::$caps, true);
}

function wp_create_nonce(string $action = ''): string
{
    return substr(md5('nonce' . $action . WpState::$user), 0, 10);
}

function wp_verify_nonce(string $nonce, string $action = ''): int|false
{
    return hash_equals(wp_create_nonce($action), $nonce) ? 1 : false;
}

function wp_nonce_field(string $action, string $name, bool $referer = true, bool $display = true): string
{
    return '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce($action) . '">';
}

/**
 * @param array<string, mixed> $args
 */
function wp_die(string $message = '', string $title = '', array $args = []): never
{
    throw new WpDieException($message);
}

function wp_safe_redirect(string $url): bool
{
    throw new WpRedirectException($url);
}

function is_admin(): bool
{
    return WpState::$isAdmin;
}

function is_multisite(): bool
{
    return WpState::$multisite;
}

/**
 * @param array<string, mixed> $args
 *
 * @return list<int>
 */
function get_sites(array $args = []): array
{
    return array_keys(WpState::$sites);
}

function switch_to_blog(int $id): bool
{
    WpState::$sites['__stack'][] = WpState::$blog;
    WpState::$blog = $id;

    return true;
}

function restore_current_blog(): bool
{
    WpState::$blog = (int) array_pop(WpState::$sites['__stack']);
    if (WpState::$sites['__stack'] === []) {
        unset(WpState::$sites['__stack']);
    }

    return true;
}

function get_current_screen(): ?object
{
    return WpState::$screen;
}

function get_locale(): string
{
    return WpState::$locale;
}

function get_bloginfo(string $show = ''): string
{
    return 'Test shop';
}

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone(WpState::$timezone);
}

function wp_date(string $format, ?int $timestamp = null): string|false
{
    return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone(wp_timezone())->format($format);
}

function human_time_diff(int $from, int $to = 0): string
{
    return (string) max(0, intdiv(abs($to - $from), 60)) . ' mins';
}

// Cron.
function wp_next_scheduled(string $hook): int|false
{
    $site = &WpState::site();

    return $site['events'][$hook] ?? false;
}

function wp_schedule_event(int $time, string $recurrence, string $hook): bool
{
    $site = &WpState::site();
    $site['events'][$hook] = $time;

    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    $site = &WpState::site();
    unset($site['events'][$hook]);

    return 1;
}

// Text, escaping, i18n.
function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html($text);
}

function _n(string $single, string $plural, int $number, string $domain = 'default'): string
{
    return $number === 1 ? $single : $plural;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_textarea(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function sanitize_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function sanitize_title(string $title): string
{
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
}

function sanitize_text_field(string $text): string
{
    return trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
}

function sanitize_textarea_field(string $text): string
{
    return trim(strip_tags($text));
}

function wp_strip_all_tags(string $text): string
{
    return trim(strip_tags((string) preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $text)));
}

function wp_slash(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('wp_slash', $value);
    }

    return is_string($value) ? addslashes($value) : $value;
}

function wp_unslash(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }

    return is_string($value) ? stripslashes($value) : $value;
}

function wp_json_encode(mixed $data, int $flags = 0): string|false
{
    return json_encode($data, $flags);
}

/**
 * A minimal kses: keeps the allowed tags without attributes and drops every
 * other tag (keeping its text), as wp_kses() does for this allow-list.
 *
 * @param array<string, array<string, bool>> $allowed
 */
function wp_kses(string $html, array $allowed): string
{
    $keep = implode('', array_map(static fn (string $t): string => '<' . $t . '>', array_keys($allowed)));
    $html = strip_tags($html, $keep);

    return (string) preg_replace('/<([a-z][a-z0-9]*)\s[^>]*?(\/?)>/i', '<$1$2>', $html);
}

function checked(mixed $a, mixed $b = true, bool $echo = true): string
{
    return $a == $b ? ' checked="checked"' : '';
}

function selected(mixed $a, mixed $b = true, bool $echo = true): string
{
    return (string) $a === (string) $b ? ' selected="selected"' : '';
}

function disabled(mixed $a, mixed $b = true, bool $echo = true): string
{
    return $a == $b ? ' disabled="disabled"' : '';
}

/**
 * @param array<string, mixed> $args
 */
function wp_dropdown_categories(array $args = []): string
{
    return '<select name="' . esc_attr((string) ($args['name'] ?? 'cat')) . '"></select>';
}

/**
 * @param array<string, mixed> $args
 */
function paginate_links(array $args = []): string
{
    return '';
}

function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
{
    WpState::$settingsErrors[] = ['setting' => $setting, 'code' => $code, 'message' => $message, 'type' => $type];
}

/**
 * @return list<array<string, mixed>>
 */
function get_settings_errors(string $setting = ''): array
{
    return WpState::$settingsErrors;
}

function settings_errors(string $setting = ''): void
{
}

function add_submenu_page(string $parent, string $title, string $menu, string $cap, string $slug, callable $cb): string
{
    return $slug;
}

function add_meta_box(string $id, string $title, callable $cb, string $screen, string $context = 'advanced', string $priority = 'default'): void
{
}

function register_activation_hook(string $file, callable $cb): void
{
}

function register_deactivation_hook(string $file, callable $cb): void
{
}

/**
 * Present so llm-json-php's WordPress transport can be built. Tests swap
 * in a mock transport; a real request here is a test bug.
 *
 * @param array<string, mixed> $args
 */
function wp_remote_post(string $url, array $args = []): never
{
    throw new LogicException('No network in tests: ' . $url);
}

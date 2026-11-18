<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Admin;

/**
 * Admin URLs used by the screens and actions.
 */
final class Url
{
    /**
     * @param array<string, scalar> $args
     */
    public static function page(string $page, array $args = []): string
    {
        return add_query_arg(array_merge(['post_type' => 'product', 'page' => $page], $args), admin_url('edit.php'));
    }

    public static function post(): string
    {
        return admin_url('admin-post.php');
    }

    public static function editProduct(int $productId): string
    {
        return add_query_arg(['post' => $productId, 'action' => 'edit'], admin_url('post.php'));
    }
}

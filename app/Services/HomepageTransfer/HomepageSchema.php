<?php

namespace App\Services\HomepageTransfer;

/**
 * The explicit allowlist of what a homepage package may contain. Anything not listed here is neither exported
 * nor imported, so unrelated columns or settings can never travel in a package by accident.
 */
final class HomepageSchema
{
    public const SECTION_TYPES = [
        'featured_category', 'banner', 'hero', 'product_carousel', 'brand_showcase', 'catalog_showcase', 'feature_list', 'notice',
    ];

    /** Item kinds each section type accepts (mirrors Admin\HomepageConfigController::validateItem). */
    public const ITEM_KINDS = [
        'hero' => ['slide'],
        'banner' => ['content'],
        'featured_category' => ['heading', 'content'],
        'product_carousel' => ['heading', 'content'],
        'brand_showcase' => ['heading', 'brand'],
        'catalog_showcase' => ['catalog'],
        'feature_list' => ['content'],
        'notice' => ['content'],
    ];

    /** Keys of homepage_configs.site_settings that belong to the homepage (mirrors the admin update rules). */
    public const SITE_SETTING_KEYS = [
        'site_name', 'site_tagline', 'logo_url', 'phone', 'email', 'address', 'search_placeholder', 'login_text', 'register_text',
        'sale_label', 'sale_url', 'newsletter_enabled', 'newsletter_title', 'newsletter_placeholder',
        'newsletter_button_text', 'seo_title', 'seo_description', 'homepage_heading', 'catalog_background_logo_url',
        'footer_copyright', 'footer_legal_notice', 'business_hours_title', 'nav_groups', 'footer_columns',
        'utility_message', 'utility_links', 'nav_links', 'nav_links_before', 'all_categories', 'business_hours',
    ];

    /** Plain (non-reference) keys of homepage_sections.settings per section type. */
    public const SECTION_SETTING_KEYS = [
        'product_carousel' => ['limit', 'source', 'action_text', 'action_url'],
        'featured_category' => ['product_limit'],
        'brand_showcase' => [],
        'hero' => ['subtitle', 'cta_text', 'cta_url', 'interval_ms'],
        'banner' => ['layout'],
        'feature_list' => ['features'],
        'catalog_showcase' => ['eyebrow', 'heading_phrases', 'description'],
        'notice' => [],
    ];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public const CLIENT_KEY_PATTERN = '/^[a-z0-9\-_]{1,64}$/';

    /** Laravel validation rules for the allowlisted site settings (same limits as the admin form). */
    public static function siteSettingRules(string $prefix = 'site_settings'): array
    {
        return [
            "$prefix" => 'array',
            "$prefix.site_name" => 'nullable|string|max:255',
            "$prefix.site_tagline" => 'nullable|string|max:255',
            "$prefix.logo_url" => 'nullable|url:http,https|max:2048',
            "$prefix.phone" => 'nullable|string|max:40',
            "$prefix.email" => 'nullable|email|max:255',
            "$prefix.address" => 'nullable|string|max:1000',
            "$prefix.search_placeholder" => 'nullable|string|max:255',
            "$prefix.login_text" => 'nullable|string|max:80',
            "$prefix.register_text" => 'nullable|string|max:80',
            "$prefix.sale_label" => 'nullable|string|max:80',
            "$prefix.sale_url" => 'nullable|string|max:2048',
            "$prefix.newsletter_enabled" => 'boolean',
            "$prefix.newsletter_title" => 'nullable|string|max:255',
            "$prefix.newsletter_placeholder" => 'nullable|string|max:255',
            "$prefix.newsletter_button_text" => 'nullable|string|max:80',
            "$prefix.seo_title" => 'nullable|string|max:255',
            "$prefix.seo_description" => 'nullable|string|max:500',
            "$prefix.homepage_heading" => 'nullable|string|max:255',
            "$prefix.catalog_background_logo_url" => 'nullable|url:http,https|max:2048',
            "$prefix.footer_copyright" => 'nullable|string|max:500',
            "$prefix.footer_legal_notice" => 'nullable|string|max:3000',
            "$prefix.business_hours_title" => 'nullable|string|max:120',
            "$prefix.nav_groups" => 'array|max:30',
            "$prefix.nav_groups.*.label" => 'required|string|max:80',
            "$prefix.nav_groups.*.keywords" => 'array|max:30',
            "$prefix.nav_groups.*.keywords.*" => 'string|max:80',
            "$prefix.nav_groups.*.url" => 'nullable|string|max:2048',
            "$prefix.nav_groups.*.names" => 'array|max:100',
            "$prefix.nav_groups.*.names.*" => 'string|max:120',
            "$prefix.footer_columns" => 'array|max:8',
            "$prefix.footer_columns.*.title" => 'required|string|max:80',
            "$prefix.footer_columns.*.links" => 'array|max:30',
            "$prefix.footer_columns.*.links.*.label" => 'required|string|max:120',
            "$prefix.footer_columns.*.links.*.url" => 'required|string|max:2048',
            "$prefix.utility_message" => 'nullable|string|max:255',
            "$prefix.utility_links" => 'array|max:8',
            "$prefix.utility_links.*.label" => 'required|string|max:80',
            "$prefix.utility_links.*.url" => 'required|string|max:2048',
            "$prefix.nav_links" => 'array|max:12',
            "$prefix.nav_links.*.label" => 'required|string|max:80',
            "$prefix.nav_links.*.url" => 'required|string|max:2048',
            "$prefix.nav_links_before" => 'array|max:12',
            "$prefix.nav_links_before.*.label" => 'required|string|max:80',
            "$prefix.nav_links_before.*.url" => 'required|string|max:2048',
            "$prefix.all_categories" => 'array|max:40',
            "$prefix.all_categories.*.label" => 'required|string|max:80',
            "$prefix.all_categories.*.url" => 'required|string|max:2048',
            "$prefix.business_hours" => 'array|max:14',
            "$prefix.business_hours.*" => 'string|max:120',
        ];
    }

    /** Laravel validation rules for the plain settings of one section type. */
    public static function sectionSettingRules(string $type, string $prefix): array
    {
        return match ($type) {
            'banner' => ["$prefix.layout" => 'nullable|in:grid,mosaic,strips,pair,wide'],
            'featured_category' => ["$prefix.product_limit" => 'nullable|integer|min:1|max:50'],
            'feature_list' => [
                "$prefix.features" => 'array|max:8',
                "$prefix.features.*.icon" => 'required|in:truck,store,clock,shield,tag,phone',
                "$prefix.features.*.title" => 'required|string|max:80',
                "$prefix.features.*.text" => 'nullable|string|max:120',
            ],
            'hero' => [
                "$prefix.subtitle" => 'nullable|string|max:500',
                "$prefix.cta_text" => 'nullable|string|max:100',
                "$prefix.cta_url" => 'nullable|string|max:2048',
                "$prefix.interval_ms" => 'nullable|integer|min:2000|max:30000',
            ],
            'product_carousel' => [
                "$prefix.limit" => 'nullable|integer|min:1|max:50',
                "$prefix.source" => 'nullable|in:manual,newest,on_sale,best_selling',
                "$prefix.action_text" => 'nullable|string|max:80',
                "$prefix.action_url" => 'nullable|string|max:2048',
            ],
            'catalog_showcase' => [
                "$prefix.eyebrow" => 'nullable|string|max:100',
                "$prefix.heading_phrases" => 'nullable|array|max:10',
                "$prefix.heading_phrases.*" => 'string|max:100',
                "$prefix.description" => 'nullable|string|max:1000',
            ],
            default => [],
        };
    }
}

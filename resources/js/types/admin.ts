export interface Media {
    id: number;
    url: string | null;
    thumbnail_url: string | null;
    public_url: string | null;
    alt: string | null;
    is_primary: boolean;
    sort_order: number;
}

export interface AdminUser {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    address: string | null;
    erp_contact_id: string | null;
    approval_status: 'pending' | 'approved' | 'rejected';
    is_admin: boolean;
    email_verified_at: string | null;
    orders_count?: number;
    created_at: string;
    updated_at: string;
    addresses?: AdminAddress[];
    orders?: AdminOrder[];
}

export interface AdminAddress {
    id: number;
    user_id: number;
    label: string | null;
    first_name: string;
    last_name: string;
    company: string | null;
    address_1: string;
    address_2: string | null;
    city: string;
    state: string | null;
    postcode: string;
    country: string;
    phone: string | null;
    is_default: boolean;
    created_at: string;
}

export interface AdminBrand {
    id: number;
    erp_brand_id: string | null;
    name: string;
    slug: string;
    description: string | null;
    is_active: boolean;
    products_count?: number;
    primary_image?: Media | null;
    media?: Media[];
    created_at: string;
    updated_at: string;
}

export interface AdminCategory {
    id: number;
    erp_category_id: string | null;
    parent_id: number | null;
    name: string;
    slug: string;
    description: string | null;
    sort_order: number;
    is_active: boolean;
    products_count?: number;
    parent?: AdminCategory | null;
    children?: AdminCategory[];
    primary_image?: Media | null;
    created_at: string;
    updated_at: string;
}

export interface AdminProduct {
    id: number;
    erp_product_id: string | null;
    parent_id: number | null;
    category_id: number | null;
    sub_category_id: number | null;
    brand_id: number | null;
    name: string;
    slug: string;
    type: 'simple' | 'variable' | 'grouped' | 'digital';
    description: string | null;
    short_description: string | null;
    sku: string;
    regular_price: string | null;
    sale_price: string | null;
    manage_stock: boolean;
    stock_quantity: number | null;
    in_stock: boolean;
    is_active: boolean;
    is_featured: boolean;
    attributes: Record<string, unknown> | null;
    synced_at: string | null;
    category?: AdminCategory | null;
    sub_category?: AdminCategory | null;
    brand?: AdminBrand | null;
    parent?: AdminProduct | null;
    children?: AdminProduct[];
    primary_image?: Media | null;
    media?: Media[];
    created_at: string;
    updated_at: string;
}

export interface AdminOrderItem {
    id: number;
    order_id: number;
    product_id: number | null;
    erp_product_id: string | null;
    name: string;
    sku: string;
    quantity: number;
    unit_price: string;
    unit_tax: string;
    total: string;
    product?: AdminProduct | null;
}

export interface AdminOrder {
    id: number;
    erp_order_id: string | null;
    invoice_no: string;
    order_key: string;
    user_id: number | null;
    status: string;
    payment_status: string;
    currency: string;
    line_total: string;
    discount_total: string;
    shipping_total: string;
    total_tax: string;
    total: string;
    billing_first_name: string;
    billing_last_name: string;
    billing_full_name?: string;
    billing_company: string | null;
    billing_address_1: string;
    billing_address_2: string | null;
    billing_city: string;
    billing_state: string | null;
    billing_postcode: string;
    billing_country: string;
    billing_email: string | null;
    billing_phone: string | null;
    shipping_first_name: string;
    shipping_last_name: string;
    shipping_full_name?: string;
    shipping_company: string | null;
    shipping_address_1: string;
    shipping_address_2: string | null;
    shipping_city: string;
    shipping_state: string | null;
    shipping_postcode: string;
    shipping_country: string;
    shipping_phone: string | null;
    customer_note: string | null;
    customer_ip_address: string | null;
    sync_status: 'pending' | 'syncing' | 'synced' | 'failed';
    sync_attempts: number;
    sync_error: string | null;
    synced_at: string | null;
    transaction_date: string | null;
    created_via?: string | null;
    items_count?: number;
    user?: AdminUser | null;
    items?: AdminOrderItem[];
    created_at: string;
    updated_at: string;
}

export type HomepageSectionType =
    | 'featured_category'
    | 'banner'
    | 'hero'
    | 'product_carousel'
    | 'brand_showcase'
    | 'catalog_showcase'
    | 'feature_list'
    | 'notice';

export interface HomepageSectionSettings {
    // featured_category
    category_id?: number;
    product_limit?: number;
    // banner / hero
    image_url?: string;
    link_url?: string;
    subtitle?: string;
    // hero
    cta_text?: string;
    cta_url?: string;
    interval_ms?: number;
    // product_carousel
    filter?: 'new' | 'sale' | 'featured';
    limit?: number;
    offset?: number;
    product_ids?: number[];
    source?: 'manual' | 'newest' | 'on_sale' | 'best_selling';
    action_text?: string;
    action_url?: string;
    // banner
    layout?: 'grid' | 'mosaic' | 'strips' | 'pair' | 'wide';
    // feature_list
    features?: { icon: string; title: string; text?: string }[];
    // brand_showcase
    brand_ids?: number[];
    // catalog_showcase
    eyebrow?: string;
    heading_phrases?: string[];
    description?: string;
}

export interface HomepageSection {
    id: number;
    homepage_config_id: number;
    type: HomepageSectionType;
    title: string;
    sort_order: number;
    is_active: boolean;
    settings: HomepageSectionSettings;
    items?: HomepageSectionItem[];
    created_at: string;
    updated_at: string;
}

export interface HomepageSectionItem {
    id: number;
    homepage_section_id: number;
    kind: 'content' | 'heading' | 'slide' | 'brand' | 'catalog';
    title: string | null;
    desktop_image_path: string | null;
    desktop_image_external_url: string | null;
    mobile_image_path: string | null;
    mobile_image_external_url: string | null;
    pdf_path: string | null;
    alt_text: string | null;
    link_url: string | null;
    sort_order: number;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

export interface HomepageConfig {
    id: number;
    client_key: string;
    label: string;
    site_settings: SiteSettings | null;
    site_logo_path: string | null;
    catalog_background_path: string | null;
    is_active: boolean;
    sections?: HomepageSection[];
    sections_count?: number;
    created_at: string;
    updated_at: string;
}

export interface SiteSettings {
    site_name?: string;
    logo_url?: string;
    phone?: string;
    email?: string;
    address?: string;
    search_placeholder?: string;
    login_text?: string;
    register_text?: string;
    sale_label?: string;
    sale_url?: string;
    newsletter_enabled?: boolean;
    newsletter_title?: string;
    newsletter_placeholder?: string;
    newsletter_button_text?: string;
    seo_title?: string;
    seo_description?: string;
    homepage_heading?: string;
    catalog_background_logo_url?: string;
    footer_copyright?: string;
    footer_legal_notice?: string;
    business_hours_title?: string;
    site_tagline?: string;
    utility_message?: string;
    utility_links?: { label: string; url: string }[];
    nav_links_before?: { label: string; url: string }[];
    nav_links?: { label: string; url: string }[];
    all_categories?: { label: string; url: string }[];
    nav_groups?: {
        label: string;
        keywords: string[];
        names?: string[];
        url?: string;
    }[];
    footer_columns?: {
        title: string;
        links: { label: string; url: string }[];
    }[];
    business_hours?: string[];
}

export interface PaginatedMeta {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
}

export interface PaginatedLinks {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
}

export interface Paginated<T> {
    data: T[];
    meta: PaginatedMeta;
    links: PaginatedLinks;
}

# Gateway — Internal Sync API

**Version:** 1.0  
**Base URL:** `{GATEWAY_URL}/internal`  
**Audience:** ERP server only — not exposed to the mobile app or public internet

---

## Overview

These endpoints are called by the ERP to push data into Gateway. All routes accept batch payloads so the ERP can sync multiple records per request. Each endpoint performs upserts — records are created on first sync and updated on subsequent syncs using ERP IDs as the stable lookup key.

**Sync order dependency:**  
Brands and Categories must be synced before Products, since Products resolve foreign keys from ERP IDs at sync time.

```
1. POST /internal/brands/sync
2. POST /internal/categories/sync
3. POST /internal/products/sync     ← depends on brands + categories
                                       grouped parent products must arrive before children
4. POST /internal/contacts/sync     ← independent
   POST /internal/orders/status/sync ← independent, triggered by ERP order state changes
```

---

## Authentication

All `/internal/*` routes require the `X-Internal-Key` header. The value must match `INTERNAL_API_KEY` configured in Gateway's `.env`.

In production, these endpoints should also be firewall-restricted to the ERP server's IP at the nginx level — the header check is a second layer, not the only layer.

| Header | Required | Description |
|---|---|---|
| `X-Internal-Key` | Yes | Shared secret matching `INTERNAL_API_KEY` on Gateway |
| `Content-Type` | Yes | `application/json` |

**Unauthorized response (401):**
```json
{
  "message": "Unauthorized"
}
```

---

## Response Envelope

All sync endpoints return the same response shape regardless of partial failures:

```json
{
  "message": "Synced {n} {resource}",
  "errors": []
}
```

| Field | Type | Description |
|---|---|---|
| `message` | string | Human-readable summary with count of successfully synced records |
| `errors` | array | Per-record errors. Empty array on full success. See each endpoint for error object shape. |

Partial success is a normal outcome — if 9 of 10 records sync cleanly and 1 fails, the response is `200` with `errors` containing the one failure. The caller should inspect `errors` to determine if a re-sync of specific records is needed.

**HTTP status codes:**

| Code | Meaning |
|---|---|
| `200` | Request processed (may include partial errors in `errors` array) |
| `401` | Missing or invalid `X-Internal-Key` |
| `422` | Validation failed — malformed request body |
| `500` | Server error |

---

## Endpoints

### POST /internal/brands/sync

Upserts brands. Syncs the primary image if an `image_url` is provided. Invalidates the brand cache on completion.

**Controller:** `App\Http\Controllers\Internal\BrandSyncController@sync`

#### Request Body

```json
{
  "brands": [
    {
      "erp_brand_id": 42,
      "name": "Acme Corp",
      "is_active": true,
      "image_url": "https://cdn.example.com/brands/acme.jpg"
    }
  ]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `brands` | array | Yes | Array of brand objects. Min 1 item. |
| `brands[].erp_brand_id` | integer | Yes | ERP's stable brand ID. Used as upsert key. |
| `brands[].name` | string | Yes | Brand display name. `slug` is auto-generated from this. |
| `brands[].is_active` | boolean | No | Defaults to `true` if omitted. |
| `brands[].image_url` | string\|null | No | URL of the primary brand image. Replaces existing primary image if present. |

#### Response

```json
{
  "message": "Synced 2 brands",
  "errors": []
}
```

**Partial failure example:**

```json
{
  "message": "Synced 1 brands",
  "errors": [
    {
      "erp_brand_id": 99,
      "error": "SQLSTATE[...]: ..."
    }
  ]
}
```

#### Behavior Notes

- Upsert key: `erp_brand_id`
- `slug` is auto-generated via `Str::slug($name)` — not accepted from ERP
- Primary image is stored in the `media` table with `is_primary = true`. Calling sync again with a new `image_url` replaces the existing primary image record
- Brand list cache (`brands:all`) and the individual brand cache (`brand:{id}`) are both invalidated after sync

---

### POST /internal/categories/sync

Upserts categories. Resolves parent-child relationships in a second pass so parent records are guaranteed to exist before children reference them. Invalidates the category cache on completion.

**Controller:** `App\Http\Controllers\Internal\CategorySyncController@sync`

#### Request Body

```json
{
  "categories": [
    {
      "erp_category_id": 10,
      "name": "Electronics",
      "is_active": true,
      "sort_order": 1,
      "image_url": "https://cdn.example.com/categories/electronics.jpg"
    },
    {
      "erp_category_id": 11,
      "name": "Phones",
      "parent_id": 10,
      "is_active": true,
      "sort_order": 1
    }
  ]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `categories` | array | Yes | Array of category objects. |
| `categories[].erp_category_id` | integer | Yes | ERP's stable category ID. Used as upsert key. |
| `categories[].name` | string | Yes | Category display name. `slug` is auto-generated from this. |
| `categories[].parent_id` | integer\|null | No | ERP ID of the parent category (not the Gateway DB ID). Resolved to Gateway's internal `parent_id` automatically. |
| `categories[].description` | string\|null | No | Optional description text. |
| `categories[].is_active` | boolean | No | Defaults to `true` if omitted. |
| `categories[].sort_order` | integer | No | Display order. Defaults to `0`. |
| `categories[].image_url` | string\|null | No | URL of the primary category image. |

#### Response

```json
{
  "message": "Synced 2 categories",
  "errors": []
}
```

**Partial failure example:**

```json
{
  "message": "Synced 1 categories",
  "errors": [
    {
      "erp_category_id": 11,
      "error": "Parent resolution failed: ..."
    }
  ]
}
```

#### Behavior Notes

- Upsert key: `erp_category_id`
- `parent_id` in the request is an **ERP category ID**, not a Gateway DB ID. Gateway resolves it internally
- Two-pass upsert: all records are created/updated in pass 1, then parent relationships are resolved in pass 2. This means parent + child categories can be sent in the same request in any order
- `slug` is auto-generated — not accepted from ERP
- Category tree cache (`categories:all`) and individual category caches are invalidated after sync

---

### POST /internal/products/sync

Upserts products. Resolves `category_id`, `sub_category_id`, `brand_id`, and `parent_id` from ERP IDs. Replaces all product images on each sync. Invalidates product cache on completion.

**Controller:** `App\Http\Controllers\Internal\ProductSyncController@sync`

**Prerequisites:**
- Brands and Categories must be synced before Products
- For grouped products: sync the grouped parent product first (with `type = "grouped"`), then sync its children (with `parent_erp_id` set). `parent_id` is resolved at sync time — if the parent row does not exist yet, it will be set to `null`

#### Product Groups

Groups are modelled as a parent product with `type = "grouped"` and a string ERP ID (e.g. `"GROUP1234"`). Individual child products point back to the group via `parent_erp_id`. This mirrors the ERP's own grouping structure.

#### Request Body

```json
{
  "products": [
    {
      "erp_product_id": "GROUP200",
      "name": "Wireless Headphones X1",
      "type": "grouped",
      "regular_price": 149.99,
      "is_active": true
    },
    {
      "erp_product_id": "201",
      "parent_erp_id": "GROUP200",
      "erp_category_id": 10,
      "erp_sub_category_id": 11,
      "erp_brand_id": 42,
      "name": "Wireless Headphones X1 — Black",
      "sku": "WH-X1-BLK",
      "type": "simple",
      "description": "Full product description here.",
      "short_description": "Compact short description.",
      "regular_price": 149.99,
      "sale_price": 119.99,
      "manage_stock": true,
      "stock_quantity": 50,
      "in_stock": true,
      "is_active": true,
      "attributes": {
        "color": "Black",
        "connectivity": "Bluetooth 5.0"
      },
      "images": [
        {
          "url": "https://cdn.example.com/products/wh-x1-main.jpg",
          "alt": "Wireless Headphones X1 front view",
          "is_primary": true
        },
        {
          "url": "https://cdn.example.com/products/wh-x1-side.jpg",
          "alt": "Wireless Headphones X1 side view",
          "is_primary": false
        }
      ]
    }
  ]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `products` | array | Yes | Array of product objects. |
| `products[].erp_product_id` | string | Yes | ERP's stable product ID. Used as upsert key. Accepts alphanumeric values — use a prefix convention (e.g. `GROUP1234`) for grouped products. |
| `products[].name` | string | Yes | Product display name. `slug` is auto-generated. |
| `products[].regular_price` | numeric | Yes | Base price before any sale. |
| `products[].parent_erp_id` | string\|null | No | ERP ID of the grouped parent product. Resolved to Gateway's internal `parent_id`. Set on child products only. |
| `products[].erp_category_id` | integer | No | ERP category ID. Resolved to Gateway `category_id`. |
| `products[].erp_sub_category_id` | integer | No | ERP sub-category ID. Resolved to Gateway `sub_category_id`. |
| `products[].erp_brand_id` | integer | No | ERP brand ID. Resolved to Gateway `brand_id`. |
| `products[].sku` | string | No | Product SKU. |
| `products[].type` | string | No | Product type. Use `grouped` for parent products, `simple` or `variable` for individual products. Defaults to `simple`. |
| `products[].description` | string\|null | No | Full product description. |
| `products[].short_description` | string\|null | No | Brief product summary. |
| `products[].sale_price` | numeric\|null | No | Discounted price. `null` means no active sale. |
| `products[].manage_stock` | boolean | No | Whether stock quantity is tracked. Defaults to `true`. |
| `products[].stock_quantity` | integer | No | Available stock units. Defaults to `0`. |
| `products[].in_stock` | boolean | No | Stock availability flag. Defaults to `true`. |
| `products[].is_active` | boolean | No | Whether product is visible in the catalog. Defaults to `true`. |
| `products[].attributes` | object\|null | No | Arbitrary key-value pairs stored as JSON (e.g. color, size, material). |
| `products[].website_url` | string\|null | No | The product's page on the storefront, max 500 characters. Returned to the app as `website_url` for share links. Omitted or `null` clears it. |
| `products[].images` | array | No | Image set for this product. If provided, **replaces all existing images**. |
| `products[].images[].url` | string | Required if `images` present | Image URL. |
| `products[].images[].alt` | string | No | Alt text. Defaults to the product name. |
| `products[].images[].is_primary` | boolean | No | Marks the primary display image. Defaults to `true` for the first image in the array. |

#### Response

```json
{
  "message": "Synced 1 products",
  "errors": []
}
```

**Partial failure example:**

```json
{
  "message": "Synced 0 products",
  "errors": [
    {
      "erp_product_id": 200,
      "error": "SQLSTATE[...]: ..."
    }
  ]
}
```

#### Behavior Notes

- Upsert key: `erp_product_id`
- `erp_category_id`, `erp_sub_category_id`, `erp_brand_id` are resolved to Gateway internal IDs at sync time. If a referenced brand or category has not been synced yet, those FK fields will be set to `null` — no error is raised
- `parent_erp_id` is resolved to Gateway's internal `parent_id` integer FK at sync time. If the referenced parent has not been synced yet, `parent_id` is set to `null`. **Sync grouped parents before children to avoid this.**
- **Image replacement:** when `images` is present, all existing media records for that product are deleted and replaced with the new set. Sort order follows array index order. If `images` is omitted, existing images are left unchanged
- `synced_at` timestamp is set to the current time on every sync
- Individual product caches (`product:{id}`, `product:{id}:siblings`) and all product list caches (`products:list:*`) are invalidated after sync

---

### POST /internal/contacts/sync

Upserts customer/user accounts. Existing users are matched by email. New users are created with a random password — they must use the forgot-password flow to gain app access.

**Controller:** `App\Http\Controllers\Internal\ContactSyncController@sync`

#### Request Body

```json
{
  "contacts": [
    {
      "erp_contact_id": 301,
      "email": "jane.doe@example.com",
      "name": "Jane Doe",
      "phone": "+1-555-0100",
      "address": "123 Main Street, Springfield, IL 62701"
    }
  ]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `contacts` | array | Yes | Array of contact objects. |
| `contacts[].erp_contact_id` | integer | Yes | ERP's stable contact ID. Stored on user record for cross-system linking. |
| `contacts[].email` | string | Yes | Contact email address. Used as the match key for existing user lookup. |
| `contacts[].name` | string | Yes | Full name. |
| `contacts[].phone` | string\|null | No | Phone number. On update, existing value is preserved if omitted. |
| `contacts[].address` | string\|null | No | Address string. On update, existing value is preserved if omitted. |

#### Response

```json
{
  "message": "Synced 1 contacts",
  "errors": []
}
```

**Partial failure example:**

```json
{
  "message": "Synced 0 contacts",
  "errors": [
    {
      "erp_contact_id": 301,
      "error": "SQLSTATE[...]: Duplicate entry ..."
    }
  ]
}
```

#### Behavior Notes

- Match key: `email`
- **Deleted accounts:** a contact whose email belongs to an account the user deleted in the app is skipped, without an error, until they register again
- **Existing user:** `name`, `phone`, `address`, and `erp_contact_id` are updated. `password` is **never overwritten**
- **New user:** created with `name`, `email`, `phone`, `address`, `erp_contact_id`, and a random 32-character hashed password. The user must go through the forgot-password flow to set their own password before logging in
- No cache invalidation — users are not cached

---

### POST /internal/orders/status/sync

Pushes order status and payment status updates from ERP back to Gateway. Lookup is by `erp_order_id` — the ERP order ID stored on the Gateway order record when the order was first dispatched.

**Controller:** `App\Http\Controllers\Internal\OrderStatusSyncController@sync`

#### Request Body

```json
{
  "orders": [
    {
      "erp_order_id": 5001,
      "status": "shipped",
      "payment_status": "paid"
    }
  ]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `orders` | array | Yes | Array of order status update objects. |
| `orders[].erp_order_id` | integer | Yes | The ERP order ID. Must match a Gateway order with this `erp_order_id`. |
| `orders[].status` | string | Yes | Customer-facing order status. Expected values: `pending`, `processing`, `shipped`, `delivered`, `cancelled`. |
| `orders[].payment_status` | string | No | Payment status. Expected values: `due`, `paid`, `refunded`. If omitted, `payment_status` is set to `null`. |
| `orders[].erp_status` | string\|null | No | The ERP's sale status, unmapped: `draft`, `final`, `quotation`. Stored only when sent. |
| `orders[].erp_shipping_status` | string\|null | No | `ordered`, `packed`, `shipped`, `delivered`, `cancelled`. Stored only when sent. |
| `orders[].erp_payment_status` | string\|null | No | `due`, `partial`, `paid`. Stored only when sent. |

#### Response

```json
{
  "message": "Synced 1 order statuses",
  "errors": []
}
```

**Order not found example:**

```json
{
  "message": "Synced 0 order statuses",
  "errors": [
    {
      "erp_order_id": 9999,
      "error": "Order not found"
    }
  ]
}
```

**Partial failure example:**

```json
{
  "message": "Synced 2 order statuses",
  "errors": [
    {
      "erp_order_id": 9999,
      "error": "Order not found"
    }
  ]
}
```

#### Behavior Notes

- Lookup key: `erp_order_id`
- If no Gateway order matches the given `erp_order_id`, an `"Order not found"` error is added to `errors` and the record is skipped — no exception is thrown
- `payment_status` is explicitly set to `null` if omitted from the payload. Send the current value if you do not intend to clear it
- This endpoint updates `status` and `payment_status` only. It does not affect `sync_status` or other sync-tracking fields on the order

---

## Validation Errors

When the request body fails validation, the response is `422 Unprocessable Content`:

```json
{
  "message": "The brands field is required.",
  "errors": {
    "brands": ["The brands field is required."]
  }
}
```

---

## Example: Full ERP Sync Sequence

A complete initial sync from ERP to Gateway follows this order:

```
POST /internal/brands/sync
  → { "brands": [ ... ] }

POST /internal/categories/sync
  → { "categories": [ ... ] }    ← include all parents and children in one call

POST /internal/products/sync
  → { "products": [ ... ] }      ← categories and brands must exist first
                                 ← grouped parent products must arrive before children

POST /internal/contacts/sync
  → { "contacts": [ ... ] }
```

For incremental updates, only the changed records need to be sent. Each endpoint is safe to call repeatedly — every call is an upsert.

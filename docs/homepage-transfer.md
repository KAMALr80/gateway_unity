# Homepage import / export

Moves **one storefront client's homepage** (for example `unity`) from one Gateway to another, typically local → live:

```
export (local) → upload (live) → validate + preview → confirm → backup → import (one transaction) → verify → done
```

Nothing changes on the target until an administrator confirms a preview, and every import can be rolled back.

## What is in a package — and what is not

| Included (homepage only) | Never included |
|---|---|
| `homepage_configs`: label, active flag, the homepage keys of `site_settings` (see `HomepageSchema::SITE_SETTING_KEYS`), uploaded site logo and catalog background | users, customers, passwords, tokens, sessions |
| `homepage_sections`: type, title, order, active flag, the allowlisted settings per type | orders, carts, addresses, payments |
| `homepage_section_items`: kind, title, alt text, link, order, active flag, images (uploaded file and/or external URL), catalog PDF | product / category / brand **records**, stock, prices |
| homepage-owned uploaded files (images, PDFs), stored by SHA-256 | `.env`, credentials, other storefront settings, full dumps |
| **references** to products, categories and brands by their ERP id (`products.erp_product_id`, `categories.erp_category_id`, `brands.erp_brand_id`) plus the name, for checking | database ids (they differ between environments) |

Settings outside the allowlist are not exported, and on import they are **kept** on the target (never deleted).

### Package layout (schema version 1)

```
homepage-unity-20261010-120000.zip
├── manifest.json     package_type "homepage", schema_version, export_id, exported_at (UTC), client_key,
│                     content_sha256 (of homepage.json), includes (record counts), warnings
├── homepage.json     the homepage content (deterministic JSON: same homepage ⇒ same bytes)
├── checksums.json    SHA-256 of every other entry
├── README.txt
└── assets/<sha256>.<jpg|png|webp|gif|pdf>
```

Checksums detect damage or edits relative to `checksums.json`; they do **not** prove who made the package. Only accept packages from your own Gateway.

## Permissions

All endpoints sit in the existing admin group: `auth` (session), `verified`, `admin` (`users.is_admin`), CSRF, and a rate limit of
10 requests/minute per administrator (`homepage-transfer`, configurable). Only the administrator who uploaded a package can confirm it.
There is no public or token-based import endpoint.

## Using it

### Admin UI

**Admin → Homepage Configs → Import / export** (`/admin/homepage-transfer`):

1. **Export**: click the client. A ZIP downloads.
2. **Import** (on the other Gateway): choose the ZIP → *Validate and preview*. You get blocking problems, warnings, reference matches and a change summary.
3. Tick the confirmation and *Confirm import*.
4. **History and backups** lists every validation, import and rollback with its status, checks and a *Roll back* button.

### Command line (export only)

```bash
php artisan homepage:export unity --output=/path/to/homepage-unity.zip
```

Read-only; refuses to overwrite an existing file.

### HTTP API (session + CSRF, JSON)

| Method | Path | Purpose |
|---|---|---|
| GET | `/admin/homepage/{config}/export` | Download the package (`application/zip`) |
| POST | `/admin/homepage-transfer/validate` | multipart `package=@file.zip` → validation + preview. **Read-only for the homepage.** 200 = `validated`, 422 = `invalid` |
| GET | `/admin/homepage-transfer/imports/{id}` | Status of one validation / import / rollback |
| POST | `/admin/homepage-transfer/imports/{id}/confirm` | `{"base_fingerprint": "<from preview>", "confirm_replace": true}` → apply |
| POST | `/admin/homepage-transfer/imports/{id}/rollback` | `{}` or `{"acknowledge_overwrite": true}` → restore the backup taken before that operation |

Responses are `{"data": {...}}` (the import record: `status`, `summary`, `validation`, `verification`, `backup.available`, `can_roll_back`, ...) or
`{"error": {"code": "...", "message": "..."}}`. File paths and package contents are never returned.

Example preview (abridged):

```json
{"data": {"id": "8f20…", "status": "validated", "client_key": "unity", "base_fingerprint": "3c1e…",
  "validation": {"errors": [], "warnings": [], "references": {"products": {"referenced": 17, "resolved": 17, "missing_count": 0}}},
  "summary": {"replaces_homepage": true, "creates_homepage": false, "unchanged": false,
    "counts": {"sections_added": 0, "sections_updated": 1, "sections_removed": 0, "items_added": 0, "items_updated": 0, "items_removed": 0},
    "sections": {"updated": [{"ref": "hero#1", "title": "Welcome", "fields": ["title"]}]}}}}
```

## How validation works

Before any content is interpreted: ZIP signature, entry count, an allowlist of entry names (no paths, no `..`, no other files), declared and
**measured** sizes (reads stop at the limit), compression ratio (ZIP bombs), total unpacked size, encrypted entries refused. Then every entry is
checked against `checksums.json`, images are decoded and PDFs checked by content (not by extension), JSON is parsed with a depth limit, the
manifest must match `homepage.json`, unknown fields are rejected, and the content is checked against the same rules as the admin forms (types,
lengths, item kinds per section, `http(s)` image URLs, no `javascript:`/`data:` links, sections and items numbered in display order).

**References** are resolved by ERP id in the target database. A missing or ambiguous product/category/brand that the homepage uses **blocks**
the import. (A product list of a carousel filled automatically — `newest`, `on_sale`, `best_selling` — is not used for display, so a missing
entry there is a warning and is dropped.) A name that differs between environments is a warning. Nothing is ever created.

The preview compares the package with the target in the same portable form. Sections are matched by type and position (`hero#1`,
`product_carousel#2`), items by kind and position, so an unchanged homepage shows **no changes** and re-importing changes nothing.

## How the import works

`confirm` runs under a per-client lock and:

1. re-validates the stored package (same bytes as previewed) and re-resolves references;
2. refuses if the target homepage changed since the preview (state fingerprint) or the package was already applied;
3. writes a **backup** of the client's homepage rows (with their ids) to the private disk and reads it back to verify it — no backup, no change;
4. in **one database transaction**: updates matched sections/items in place, adds new ones, removes ones not in the package, stores package files
   under `homepage/<client>/<section id>/<sha256>.<ext>`, checks the stored result equals the package, and records success;
5. after the commit, builds the homepage through the public `GET /api/homepage?client=…` code path and checks status, client and section order;
6. dispatches `App\Events\HomepageImported` (hook for any future cache/CDN refresh).

If step 4 fails, the transaction rolls back and files written by that attempt are deleted. If step 5 fails, the backup is restored
automatically (`rolled_back`); if that also fails the import is marked `recovery_required`.

**Caching:** the Gateway builds the homepage payload on every request — there is no server-side homepage cache to clear, so nothing is flushed.
The storefront (`frontend/lib/site.ts`) caches the homepage for up to 5 minutes (`revalidate`), so a change can take up to 5 minutes to appear there.

**Idempotency:** a package (export id + content hash) can be applied to a client once; a unique `idempotency_key` enforces this even under races.
To apply the same content again, export a new package or roll back. A failed or rolled-back attempt does not count as applied.

## Backups and rollback

Backups are JSON files in `storage/app/private/homepage-backups/<client>/<uuid>.json` (never web-served); the `homepage_imports` table keeps their
SHA-256. Rollback (from the history) verifies the checksum, refuses if the homepage was edited since the operation (unless acknowledged),
takes a backup of the current state first (so a rollback can itself be rolled back), restores the rows with their original ids in one
transaction, verifies the result and the public API, and dispatches `App\Events\HomepageRestored`. A backup can only be restored into the client
it was taken from.

Uploaded homepage files are **never deleted** by import or rollback, so old backups stay restorable. Files no longer referenced stay on disk.

## Statuses

`validated` → `in_progress` → `succeeded` | `failed` | `recovery_required` | `rolled_back`; `succeeded` → `rolled_back` | `recovery_required`;
`recovery_required` → `rolled_back`. `invalid`, `failed`, `expired`, `rolled_back` are final. A validation expires after 60 minutes. An operation
`in_progress` for more than 15 minutes is treated as interrupted (an import is marked `failed` — it never committed; a rollback `recovery_required`).

## Deployment prerequisites

1. Deploy the code.
2. Run the one additive migration **when you decide to** (it creates `homepage_imports` only and touches no existing table):
   `php artisan migrate` (`2026_10_10_000001_create_homepage_imports_table`). Imports never run migrations themselves.
3. Optional `.env`: `HOMEPAGE_TRANSFER_ENV=local` (label shown in packages; never a URL). Limits are in `config/homepage_transfer.php`.
4. PHP extensions `zip` and `fileinfo` (already required by the app).

## Recovery

- `failed`: nothing changed. Fix the cause and validate again.
- `rolled_back` with category `verification_failed`: the previous homepage was restored automatically; check the history's checks.
- `recovery_required`: the homepage may not match either version. Use **Roll back** on that entry (restores its verified backup), or restore a
  backup file manually via `App\Services\HomepageTransfer\HomepageBackup::restore()` inside a transaction.

## Known limitations

- References need ERP ids that exist in **both** environments. If the local catalog differs from the live one, the import is blocked — by design —
  until the homepage uses products that exist live.
- Section/item identity is positional (type + position): reordering two sections of the same type is shown as updates of both.
- Only the client named in the package can be imported (no import of one client's package into another client).
- Files no longer used remain on disk (by design, for rollback).

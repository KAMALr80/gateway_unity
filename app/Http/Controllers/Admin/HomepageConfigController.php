<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\HomepageConfig;
use App\Models\HomepageSection;
use App\Models\HomepageSectionItem;
use App\Models\Product;
use App\Services\FrontendRevalidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Psr\Http\Message\UriInterface;

class HomepageConfigController extends Controller
{
    public function index()
    {
        $configs = HomepageConfig::withCount('sections')->latest()->get();

        return Inertia::render('admin/homepage/index', [
            'configs' => $configs,
        ]);
    }

    public function create()
    {
        return Inertia::render('admin/homepage/create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'client_key' => 'required|string|max:64|unique:homepage_configs,client_key|regex:/^[a-z0-9\-_]+$/',
            'label' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $config = HomepageConfig::create($data);

        return redirect()->route('admin.homepage.edit', $config)->with('success', 'Client config created.');
    }

    public function edit(HomepageConfig $config)
    {
        $config->load('sections.items');

        return Inertia::render('admin/homepage/edit', [
            'config' => $config,
            'categories' => Category::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
            'brands' => Brand::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
            'products' => Product::select('id', 'name', 'sku')->where('is_active', true)->whereNull('parent_id')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, HomepageConfig $config)
    {
        $data = $request->validate([
            'client_key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9\-_]+$/', Rule::in([$config->client_key]), Rule::unique('homepage_configs')->ignore($config->id)],
            'label' => 'required|string|max:255',
            'is_active' => 'boolean',
            'site_settings' => 'required|array',
            'site_settings.site_name' => 'nullable|string|max:255',
            'site_settings.logo_url' => $request->hasFile('site_logo')
                ? ['nullable', 'string', 'max:2048']
                : ['nullable', 'url:http,https', 'max:2048'],
            'site_settings.phone' => 'nullable|string|max:40',
            'site_settings.email' => 'nullable|email|max:255',
            'site_settings.address' => 'nullable|string|max:1000',
            'site_settings.search_placeholder' => 'nullable|string|max:255',
            'site_settings.login_text' => 'nullable|string|max:80',
            'site_settings.register_text' => 'nullable|string|max:80',
            'site_settings.sale_label' => 'nullable|string|max:80',
            'site_settings.sale_url' => 'nullable|string|max:2048',
            'site_settings.newsletter_enabled' => 'boolean',
            'site_settings.newsletter_title' => 'nullable|string|max:255',
            'site_settings.newsletter_placeholder' => 'nullable|string|max:255',
            'site_settings.newsletter_button_text' => 'nullable|string|max:80',
            'site_settings.seo_title' => 'nullable|string|max:255',
            'site_settings.seo_description' => 'nullable|string|max:500',
            'site_settings.homepage_heading' => 'nullable|string|max:255',
            'site_settings.catalog_background_logo_url' => $request->hasFile('catalog_background_image')
                ? ['nullable', 'string', 'max:2048']
                : ['nullable', 'url:http,https', 'max:2048'],
            'site_settings.footer_copyright' => 'nullable|string|max:500',
            'site_settings.footer_legal_notice' => 'nullable|string|max:3000',
            'site_settings.business_hours_title' => 'nullable|string|max:120',
            'site_settings.nav_groups' => 'array|max:30',
            'site_settings.nav_groups.*.label' => 'required|string|max:80',
            'site_settings.nav_groups.*.keywords' => 'array|max:30',
            'site_settings.nav_groups.*.keywords.*' => 'string|max:80',
            'site_settings.nav_groups.*.url' => 'nullable|string|max:2048',
            'site_settings.nav_groups.*.names' => 'array|max:100',
            'site_settings.nav_groups.*.names.*' => 'string|max:120',
            'site_settings.site_tagline' => 'nullable|string|max:255',
            'site_settings.nav_links_before' => 'array|max:12',
            'site_settings.nav_links_before.*.label' => 'required|string|max:80',
            'site_settings.nav_links_before.*.url' => 'required|string|max:2048',
            'site_settings.all_categories' => 'array|max:40',
            'site_settings.all_categories.*.label' => 'required|string|max:80',
            'site_settings.all_categories.*.url' => 'required|string|max:2048',
            'site_settings.footer_columns' => 'array|max:8',
            'site_settings.footer_columns.*.title' => 'required|string|max:80',
            'site_settings.footer_columns.*.links' => 'array|max:30',
            'site_settings.footer_columns.*.links.*.label' => 'required|string|max:120',
            'site_settings.footer_columns.*.links.*.url' => 'required|string|max:2048',
            'site_settings.utility_message' => 'nullable|string|max:255',
            'site_settings.utility_links' => 'array|max:8',
            'site_settings.utility_links.*.label' => 'required|string|max:80',
            'site_settings.utility_links.*.url' => 'required|string|max:2048',
            'site_settings.nav_links' => 'array|max:12',
            'site_settings.nav_links.*.label' => 'required|string|max:80',
            'site_settings.nav_links.*.url' => 'required|string|max:2048',
            'site_settings.business_hours' => 'array|max:14',
            'site_settings.business_hours.*' => 'string|max:120',
            'site_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:5120',
            'catalog_background_image' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:5120',
            'remove_site_logo' => 'sometimes|boolean',
            'remove_catalog_background' => 'sometimes|boolean',
        ]);

        if ($request->hasFile('site_logo') && $request->boolean('remove_site_logo')) {
            throw ValidationException::withMessages(['site_logo' => 'Choose a new logo or remove the current logo, not both.']);
        }

        if ($request->hasFile('catalog_background_image') && $request->boolean('remove_catalog_background')) {
            throw ValidationException::withMessages(['catalog_background_image' => 'Choose a new background or remove the current background, not both.']);
        }

        // Keep site settings the admin form does not send (they are only replaced when posted).
        $data['site_settings'] = array_merge(
            $config->site_settings ?? [],
            $this->normalizeSiteSettings($request, $data['site_settings']),
        );

        $oldClientKey = $config->client_key;
        $directory = "homepage/{$data['client_key']}/site";
        $newPaths = [];
        $oldPaths = [];

        if ($request->hasFile('site_logo')) {
            $data['site_logo_path'] = $request->file('site_logo')->store($directory, 'public');
            $data['site_settings']['logo_url'] = null;
            $newPaths[] = $data['site_logo_path'];
            $oldPaths[] = $config->site_logo_path;
        } elseif ($request->boolean('remove_site_logo')) {
            $data['site_logo_path'] = null;
            $oldPaths[] = $config->site_logo_path;
        }

        if ($request->hasFile('catalog_background_image')) {
            $data['catalog_background_path'] = $request->file('catalog_background_image')->store($directory, 'public');
            $data['site_settings']['catalog_background_logo_url'] = null;
            $newPaths[] = $data['catalog_background_path'];
            $oldPaths[] = $config->catalog_background_path;
        } elseif ($request->boolean('remove_catalog_background')) {
            $data['catalog_background_path'] = null;
            $oldPaths[] = $config->catalog_background_path;
        }

        unset(
            $data['site_logo'],
            $data['catalog_background_image'],
            $data['remove_site_logo'],
            $data['remove_catalog_background'],
        );

        try {
            $config->update($data);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($newPaths);

            throw $exception;
        }

        foreach ($oldPaths as $oldPath) {
            if ($oldPath && ! in_array($oldPath, $newPaths, true)) {
                $this->deleteOwnedSitePath($oldClientKey, $oldPath);
            }
        }

        return back()->with('success', 'Config updated.');
    }

    public function destroy(HomepageConfig $config)
    {
        $config->load('sections.items');
        foreach ($config->sections as $section) {
            foreach ($section->items as $item) {
                $this->deleteOwnedFiles($config, $section, $item);
            }
        }
        $this->deleteOwnedSitePath($config->client_key, $config->site_logo_path);
        $this->deleteOwnedSitePath($config->client_key, $config->catalog_background_path);
        $config->delete();

        return redirect()->route('admin.homepage.index')->with('success', 'Config deleted.');
    }

    public function storeSection(Request $request, HomepageConfig $config)
    {
        $base = $request->validate([
            'type' => 'required|in:featured_category,banner,hero,product_carousel,brand_showcase,catalog_showcase,feature_list,notice',
            'title' => 'required|string|max:255',
            'is_active' => 'boolean',
            'settings' => 'array',
        ]);

        $settings = $this->validateSettings($request, $base['type']);

        $maxOrder = $config->sections()->max('sort_order') ?? -1;

        $config->sections()->create([
            'type' => $base['type'],
            'title' => $base['title'],
            'is_active' => $base['is_active'] ?? true,
            'settings' => $settings,
            'sort_order' => $maxOrder + 1,
        ]);

        return back()->with('success', 'Section added.');
    }

    public function updateSection(Request $request, HomepageConfig $config, HomepageSection $section)
    {
        abort_if($section->homepage_config_id !== $config->id, 404);

        $base = $request->validate([
            'type' => 'required|in:featured_category,banner,hero,product_carousel,brand_showcase,catalog_showcase,feature_list,notice',
            'title' => 'required|string|max:255',
            'is_active' => 'boolean',
            'settings' => 'array',
        ]);

        $settings = $this->validateSettings($request, $base['type']);

        // The admin form does not edit these yet; keep what is stored instead of dropping it on every save.
        if ($section->type === $base['type']) {
            foreach (['source', 'layout', 'action_text', 'action_url', 'features'] as $key) {
                if (! array_key_exists($key, $settings) && array_key_exists($key, $section->settings ?? [])) {
                    $settings[$key] = $section->settings[$key];
                }
            }
        }

        $section->update([
            'type' => $base['type'],
            'title' => $base['title'],
            'is_active' => $base['is_active'] ?? $section->is_active,
            'settings' => $settings,
        ]);

        return back()->with('success', 'Section updated.');
    }

    public function destroySection(HomepageConfig $config, HomepageSection $section)
    {
        abort_if($section->homepage_config_id !== $config->id, 404);

        foreach ($section->items as $item) {
            $this->deleteOwnedFiles($config, $section, $item);
        }

        $section->delete();

        return back()->with('success', 'Section removed.');
    }

    public function reorderSections(Request $request, HomepageConfig $config)
    {
        $request->validate([
            'sections' => 'required|array',
            'sections.*.id' => 'required|integer',
            'sections.*.sort_order' => 'required|integer|min:0',
        ]);

        $idsForConfig = $config->sections()->pluck('id')->all();

        foreach ($request->sections as $item) {
            if (in_array($item['id'], $idsForConfig)) {
                HomepageSection::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
            }
        }
        // A query-builder update raises no model events, so ask for the storefront refresh explicitly.
        app(FrontendRevalidator::class)->queue();

        return back()->with('success', 'Order saved.');
    }

    public function storeItem(Request $request, HomepageConfig $config, HomepageSection $section)
    {
        $this->assertSectionBelongsToConfig($config, $section);
        $data = $this->validateItem($request, true, $section);
        if ($section->type === 'product_carousel' && $data['kind'] === 'content' && $section->items()->where('kind', 'content')->count() >= 2) {
            throw ValidationException::withMessages(['kind' => 'A product section supports at most two promotional banners.']);
        }
        $directory = "homepage/{$config->client_key}/{$section->id}";
        $data['desktop_image_path'] = $request->hasFile('desktop_image')
            ? $request->file('desktop_image')->store($directory, 'public')
            : $this->storeRemoteImage($data['desktop_image_external_url'], $directory, 'desktop_image_external_url');
        $data['desktop_image_external_url'] = $request->hasFile('desktop_image')
            ? null
            : ($data['desktop_image_external_url'] ?? null);
        $data['mobile_image_path'] = $request->hasFile('mobile_image')
            ? $request->file('mobile_image')->store($directory, 'public')
            : (filled($data['mobile_image_external_url'] ?? null)
                ? $this->storeRemoteImage($data['mobile_image_external_url'], $directory, 'mobile_image_external_url')
                : null);
        $data['mobile_image_external_url'] = $request->hasFile('mobile_image')
            ? null
            : ($data['mobile_image_external_url'] ?? null);
        $data['pdf_path'] = $request->hasFile('pdf_file')
            ? $request->file('pdf_file')->store("{$directory}/catalogs", 'public')
            : null;
        unset($data['desktop_image'], $data['mobile_image'], $data['pdf_file']);

        if ($data['kind'] === 'heading') {
            $existingHeading = $section->items()->where('kind', 'heading')->first();
            if ($existingHeading) {
                $this->deleteOwnedFiles($config, $section, $existingHeading);
                $existingHeading->update($data);

                return back()->with('success', 'Heading banner replaced.');
            }
        }

        $data['sort_order'] = ($section->items()->max('sort_order') ?? -1) + 1;
        $section->items()->create($data);

        return back()->with('success', 'Homepage image added.');
    }

    public function updateItem(Request $request, HomepageConfig $config, HomepageSection $section, HomepageSectionItem $item)
    {
        $this->assertItemBelongsToSection($config, $section, $item);
        $data = $this->validateItem($request, false, $section);
        $directory = "homepage/{$config->client_key}/{$section->id}";

        if ($request->hasFile('desktop_image')) {
            $newPath = $request->file('desktop_image')->store($directory, 'public');
            $this->deleteOwnedPath($config, $section, $item->desktop_image_path);
            $data['desktop_image_path'] = $newPath;
            $data['desktop_image_external_url'] = null;
        } elseif ($request->filled('desktop_image_external_url') && (
            $data['desktop_image_external_url'] !== $item->desktop_image_external_url || ! $item->desktop_image_path
        )) {
            $newPath = $this->storeRemoteImage($data['desktop_image_external_url'], $directory, 'desktop_image_external_url');
            $this->deleteOwnedPath($config, $section, $item->desktop_image_path);
            $data['desktop_image_path'] = $newPath;
        }
        if ($request->hasFile('mobile_image')) {
            if ($item->mobile_image_path) {
                $this->deleteOwnedPath($config, $section, $item->mobile_image_path);
            }
            $data['mobile_image_path'] = $request->file('mobile_image')->store($directory, 'public');
            $data['mobile_image_external_url'] = null;
        } elseif ($request->filled('mobile_image_external_url') && (
            $data['mobile_image_external_url'] !== $item->mobile_image_external_url || ! $item->mobile_image_path
        )) {
            $newPath = $this->storeRemoteImage($data['mobile_image_external_url'], $directory, 'mobile_image_external_url');
            $this->deleteOwnedPath($config, $section, $item->mobile_image_path);
            $data['mobile_image_path'] = $newPath;
        }

        if ($request->hasFile('pdf_file')) {
            $newPdfPath = $request->file('pdf_file')->store("{$directory}/catalogs", 'public');
            $this->deleteOwnedPath($config, $section, $item->pdf_path);
            $data['pdf_path'] = $newPdfPath;
        }

        unset($data['desktop_image'], $data['mobile_image'], $data['pdf_file']);
        $item->update($data);

        return back()->with('success', 'Homepage image updated.');
    }

    public function destroyItem(HomepageConfig $config, HomepageSection $section, HomepageSectionItem $item)
    {
        $this->assertItemBelongsToSection($config, $section, $item);
        $this->deleteOwnedFiles($config, $section, $item);
        $item->delete();

        return back()->with('success', 'Homepage image removed.');
    }

    public function reorderItems(Request $request, HomepageConfig $config, HomepageSection $section)
    {
        $this->assertSectionBelongsToConfig($config, $section);
        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);
        $allowedIds = $section->items()->pluck('id')->all();
        foreach ($validated['items'] as $entry) {
            if (in_array($entry['id'], $allowedIds, true)) {
                HomepageSectionItem::whereKey($entry['id'])->update(['sort_order' => $entry['sort_order']]);
            }
        }

        return back()->with('success', 'Image order saved.');
    }

    public function storeBrandLogos(Request $request, HomepageConfig $config, HomepageSection $section)
    {
        $this->assertSectionBelongsToConfig($config, $section);
        abort_unless($section->type === 'brand_showcase', 422, 'Bulk logo upload is only available for brand sections.');

        $validated = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:14'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $directory = "homepage/{$config->client_key}/{$section->id}/brands";
        $stored = [];
        foreach ($validated['images'] as $index => $image) {
            $path = $image->store($directory, 'public');
            $stored[] = [
                'kind' => 'brand',
                'title' => Str::of($image->getClientOriginalName())->beforeLast('.')->replace(['-', '_'], ' ')->title()->toString(),
                'desktop_image_path' => $path,
                'mobile_image_path' => null,
                'alt_text' => Str::of($image->getClientOriginalName())->beforeLast('.')->replace(['-', '_'], ' ')->title()->toString(),
                'link_url' => '/brands',
                'sort_order' => $index + 1,
                'is_active' => true,
            ];
        }

        $oldItems = $section->items()->where('kind', 'brand')->get();
        $oldItems->each(fn (HomepageSectionItem $item) => $this->deleteOwnedFiles($config, $section, $item));
        $section->items()->where('kind', 'brand')->delete();
        $section->items()->createMany($stored);

        return back()->with('success', count($stored).' brand logos uploaded.');
    }

    private function validateItem(Request $request, bool $creating, HomepageSection $section): array
    {
        $allowedKinds = match ($section->type) {
            'hero' => ['slide'],
            'banner' => ['content'],
            'featured_category', 'product_carousel' => ['heading', 'content'],
            'brand_showcase' => ['heading', 'brand'],
            'catalog_showcase' => ['catalog'],
            default => ['content'],
        };

        $data = $request->validate([
            'kind' => ['required', Rule::in($allowedKinds)],
            'title' => ['nullable', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'link_url' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'desktop_image' => $request->hasFile('desktop_image')
                ? ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120']
                : ['nullable'],
            'desktop_image_external_url' => ['nullable', 'url:http,https', 'max:2048'],
            'mobile_image' => $request->hasFile('mobile_image')
                ? ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120']
                : ['nullable'],
            'mobile_image_external_url' => ['nullable', 'url:http,https', 'max:2048'],
            'pdf_file' => [
                $section->type === 'catalog_showcase' && $creating ? 'required' : 'nullable',
                'file',
                'mimes:pdf',
                'mimetypes:application/pdf',
                'max:51200',
            ],
        ]);

        if ($creating && ! $request->hasFile('desktop_image') && blank($data['desktop_image_external_url'] ?? null)) {
            throw ValidationException::withMessages([
                'desktop_image' => 'Upload a desktop image or enter a desktop image URL.',
            ]);
        }

        return $data;
    }

    private function assertSectionBelongsToConfig(HomepageConfig $config, HomepageSection $section): void
    {
        abort_if($section->homepage_config_id !== $config->id, 404);
    }

    private function assertItemBelongsToSection(HomepageConfig $config, HomepageSection $section, HomepageSectionItem $item): void
    {
        $this->assertSectionBelongsToConfig($config, $section);
        abort_if($item->homepage_section_id !== $section->id, 404);
    }

    private function validateSettings(Request $request, string $type): array
    {
        return match ($type) {
            'notice' => [],

            'banner' => $request->validate([
                'settings.layout' => 'nullable|in:grid,mosaic,strips,pair,wide',
            ])['settings'] ?? [],

            'featured_category' => $request->validate([
                'settings.category_id' => 'nullable|integer|exists:categories,id',
                'settings.product_limit' => 'nullable|integer|min:1|max:50',
            ])['settings'] ?? [],

            'brand_showcase' => $request->validate([
                'settings.brand_ids' => 'nullable|array',
                'settings.brand_ids.*' => 'integer|distinct|exists:brands,id',
            ])['settings'] ?? [],

            'feature_list' => $request->validate([
                'settings.features' => 'array|max:8',
                'settings.features.*.icon' => 'required|in:truck,store,clock,shield,tag,phone',
                'settings.features.*.title' => 'required|string|max:80',
                'settings.features.*.text' => 'nullable|string|max:120',
            ])['settings'] ?? [],

            'hero' => $request->validate([
                'settings.subtitle' => 'nullable|string|max:500',
                'settings.cta_text' => 'nullable|string|max:100',
                'settings.cta_url' => 'nullable|string|max:2048',
                'settings.interval_ms' => 'nullable|integer|min:2000|max:30000',
            ])['settings'] ?? [],

            'product_carousel' => $request->validate([
                'settings.limit' => 'integer|min:1|max:50',
                'settings.source' => 'nullable|in:manual,newest,on_sale,best_selling',
                'settings.action_text' => 'nullable|string|max:80',
                'settings.action_url' => 'nullable|string|max:2048',
                'settings.product_ids' => 'present|array|max:50',
                'settings.product_ids.*' => 'integer|distinct|exists:products,id',
            ])['settings'] ?? [],

            'catalog_showcase' => $request->validate([
                'settings.eyebrow' => 'nullable|string|max:100',
                'settings.heading_phrases' => 'nullable|array|max:10',
                'settings.heading_phrases.*' => 'string|max:100',
                'settings.description' => 'nullable|string|max:1000',
            ])['settings'] ?? [],

            default => [],
        };
    }

    private function deleteOwnedFiles(HomepageConfig $config, HomepageSection $section, HomepageSectionItem $item): void
    {
        foreach ([$item->desktop_image_path, $item->mobile_image_path, $item->pdf_path] as $path) {
            $this->deleteOwnedPath($config, $section, $path);
        }
    }

    private function storeRemoteImage(string $url, string $directory, string $field): string
    {
        $this->assertPublicHost($url, $field);

        try {
            $origin = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).'/';
            $client = Http::connectTimeout(5)
                ->timeout(15)
                ->withOptions([
                    // The host check only resolves IPv4 addresses, so never connect over IPv6.
                    'force_ip_resolve' => 'v4',
                    // Every redirect hop must pass the same public-host check as the original URL.
                    'allow_redirects' => [
                        'max' => 3,
                        'protocols' => ['http', 'https'],
                        'on_redirect' => fn ($request, $response, UriInterface $uri) => $this->assertPublicHost((string) $uri, $field),
                    ],
                ])
                ->withHeaders(['Referer' => $origin, 'User-Agent' => 'Gateway/1.0']);
            // The local Windows PHP installation uses a self-signed development
            // certificate chain. Production continues to require TLS verification.
            if (app()->environment('local')) {
                $client = $client->withoutVerifying();
            }
            $response = $client->get($url);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'The image could not be downloaded from this URL.']);
        }

        $contentType = strtolower(trim(explode(';', $response->header('Content-Type', ''))[0]));
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        $body = $response->body();
        if (! $response->successful() || ! isset($extensions[$contentType]) || strlen($body) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([$field => 'The URL must return a JPG, PNG, WebP, or GIF image no larger than 5 MB.']);
        }

        $path = trim($directory, '/').'/remote-'.Str::uuid().'.'.$extensions[$contentType];
        Storage::disk('public')->put($path, $body);

        return $path;
    }

    private function assertPublicHost(string $url, string $field): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $ips = $host ? gethostbynamel($host) : false;
        if (! $host || ! $ips || collect($ips)->contains(fn (string $ip) => filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE,
        ) === false)) {
            throw ValidationException::withMessages([$field => 'The image URL must point to a publicly accessible server.']);
        }
    }

    /**
     * The edit page submits multipart form data, which sends booleans as "0"/"1"
     * and omits empty arrays. Restore the JSON shape the storefront and edit page read.
     */
    private function normalizeSiteSettings(Request $request, array $settings): array
    {
        if (array_key_exists('newsletter_enabled', $settings)) {
            $settings['newsletter_enabled'] = $request->boolean('site_settings.newsletter_enabled');
        }

        $settings['nav_groups'] = array_map(
            fn (array $group) => $group + ['keywords' => []],
            $settings['nav_groups'] ?? [],
        );
        $settings['footer_columns'] = array_map(
            fn (array $column) => $column + ['links' => []],
            $settings['footer_columns'] ?? [],
        );
        $settings['business_hours'] ??= [];

        return $settings;
    }

    private function deleteOwnedPath(HomepageConfig $config, HomepageSection $section, ?string $path): void
    {
        $prefix = "homepage/{$config->client_key}/{$section->id}/";
        if ($path && str_starts_with($path, $prefix) && ! str_contains($path, '..')) {
            Storage::disk('public')->delete($path);
        }
    }

    private function deleteOwnedSitePath(string $clientKey, ?string $path): void
    {
        $prefix = "homepage/{$clientKey}/site/";

        if ($path && str_starts_with($path, $prefix) && ! str_contains($path, '..')) {
            Storage::disk('public')->delete($path);
        }
    }
}

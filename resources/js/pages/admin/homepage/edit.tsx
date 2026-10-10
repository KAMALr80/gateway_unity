import { Head, router, useForm } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronUp,
    ExternalLink,
    Globe2,
    ImagePlus,
    Images,
    Layers3,
    Pencil,
    Plus,
    Save,
    Search,
    Settings2,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import adminRoutes from '@/routes/admin';
import type { BreadcrumbItem } from '@/types';
import type {
    AdminBrand,
    AdminCategory,
    AdminProduct,
    HomepageConfig,
    HomepageSection,
    HomepageSectionItem,
    HomepageSectionSettings,
    HomepageSectionType,
    SiteSettings,
} from '@/types/admin';

interface Props {
    config: HomepageConfig & { sections: HomepageSection[] };
    categories: Pick<AdminCategory, 'id' | 'name'>[];
    brands: Pick<AdminBrand, 'id' | 'name'>[];
    products: Pick<AdminProduct, 'id' | 'name' | 'sku'>[];
}

const SECTION_LABELS: Record<HomepageSectionType, string> = {
    featured_category: 'Featured Category',
    banner: 'Banner',
    hero: 'Hero',
    product_carousel: 'Product Carousel',
    brand_showcase: 'Brand Showcase',
    catalog_showcase: 'Catalog Showcase',
    feature_list: 'Feature List',
    notice: 'Notice Heading',
};

function sectionBadgeColor(type: HomepageSectionType) {
    const map: Record<HomepageSectionType, string> = {
        featured_category:
            'bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-900/20 dark:text-blue-400',
        banner: 'bg-orange-100 text-orange-800 border-orange-200 dark:bg-orange-900/20 dark:text-orange-400',
        hero: 'bg-purple-100 text-purple-800 border-purple-200 dark:bg-purple-900/20 dark:text-purple-400',
        product_carousel:
            'bg-teal-100 text-teal-800 border-teal-200 dark:bg-teal-900/20 dark:text-teal-400',
        brand_showcase:
            'bg-yellow-100 text-yellow-800 border-yellow-200 dark:bg-yellow-900/20 dark:text-yellow-400',
        feature_list:
            'bg-emerald-100 text-emerald-800 border-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-400',
        notice: 'bg-slate-100 text-slate-800 border-slate-200 dark:bg-slate-900/20 dark:text-slate-400',
        catalog_showcase:
            'bg-indigo-100 text-indigo-800 border-indigo-200 dark:bg-indigo-900/20 dark:text-indigo-400',
    };

    return map[type] ?? '';
}

function externalUrlValue(value: string | null | undefined): string {
    return value && /^https?:\/\//i.test(value) ? value : '';
}

function settingsSummary(
    section: HomepageSection,
    categories: Props['categories'],
): string {
    const s = section.settings ?? {};

    switch (section.type) {
        case 'featured_category': {
            const cat = categories.find((c) => c.id === s.category_id);

            return cat
                ? `${cat.name} · limit ${s.product_limit ?? 8}`
                : 'No category set';
        }
        case 'banner':
            return s.image_url
                ? `Image: ${s.image_url.slice(0, 40)}…`
                : 'No image set';
        case 'hero':
            return (
                [s.subtitle, s.cta_text].filter(Boolean).join(' · ') ||
                'No content set'
            );
        case 'product_carousel':
            return `${s.product_ids?.length ?? 0} selected products · limit ${s.limit ?? 14}`;
        case 'brand_showcase': {
            const count = s.brand_ids?.length ?? 0;

            return `${count} brand${count !== 1 ? 's' : ''} selected`;
        }
        case 'catalog_showcase':
            return s.eyebrow || 'Catalog covers and PDFs';
        default:
            return '';
    }
}

// ─── Section Form ──────────────────────────────────────────────────────────────

interface SectionFormData {
    type: HomepageSectionType;
    title: string;
    is_active: boolean;
    settings: HomepageSectionSettings;
}

function SectionFields({
    data,
    setData,
    errors,
    categories,
    brands,
    products,
}: {
    data: SectionFormData;
    setData: (key: keyof SectionFormData, value: unknown) => void;
    errors: Partial<Record<string, string>>;
    categories: Props['categories'];
    brands: Props['brands'];
    products: Props['products'];
}) {
    function setSetting(key: keyof HomepageSectionSettings, value: unknown) {
        setData('settings', { ...data.settings, [key]: value });
    }

    return (
        <div className="space-y-4">
            <div className="grid gap-2">
                <Label htmlFor="s-type">Section Type</Label>
                <Select
                    value={data.type}
                    onValueChange={(v) =>
                        setData('type', v as HomepageSectionType)
                    }
                >
                    <SelectTrigger id="s-type">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {(
                            Object.entries(SECTION_LABELS) as [
                                HomepageSectionType,
                                string,
                            ][]
                        ).map(([value, label]) => (
                            <SelectItem key={value} value={value}>
                                {label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="s-title">Title</Label>
                <Input
                    id="s-title"
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    required
                />
                <InputError message={errors.title} />
            </div>

            {data.type === 'featured_category' && (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="s-category">Category</Label>
                        <Select
                            value={String(data.settings.category_id ?? '')}
                            onValueChange={(v) =>
                                setSetting('category_id', parseInt(v))
                            }
                        >
                            <SelectTrigger id="s-category">
                                <SelectValue placeholder="Select a category" />
                            </SelectTrigger>
                            <SelectContent>
                                {categories.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {c.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors['settings.category_id']} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="s-limit">Product Limit</Label>
                        <Input
                            id="s-limit"
                            type="number"
                            min={1}
                            max={50}
                            value={data.settings.product_limit ?? 8}
                            onChange={(e) =>
                                setSetting(
                                    'product_limit',
                                    parseInt(e.target.value),
                                )
                            }
                        />
                    </div>
                </>
            )}

            {data.type === 'banner' && (
                <p className="rounded-md border bg-muted/40 p-3 text-sm text-muted-foreground">
                    Upload one or more banner images after saving this section.
                </p>
            )}

            {data.type === 'hero' && (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="s-subtitle">Subtitle</Label>
                        <Input
                            id="s-subtitle"
                            value={data.settings.subtitle ?? ''}
                            onChange={(e) =>
                                setSetting('subtitle', e.target.value)
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="s-cta-text">CTA Button Text</Label>
                        <Input
                            id="s-cta-text"
                            value={data.settings.cta_text ?? ''}
                            onChange={(e) =>
                                setSetting('cta_text', e.target.value)
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="s-cta-url">CTA URL</Label>
                        <Input
                            id="s-cta-url"
                            value={data.settings.cta_url ?? ''}
                            onChange={(e) =>
                                setSetting('cta_url', e.target.value)
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="s-interval">
                            Slide interval (milliseconds)
                        </Label>
                        <Input
                            id="s-interval"
                            type="number"
                            min={2000}
                            max={30000}
                            value={data.settings.interval_ms ?? 5000}
                            onChange={(e) =>
                                setSetting(
                                    'interval_ms',
                                    parseInt(e.target.value) || 5000,
                                )
                            }
                        />
                    </div>
                </>
            )}

            {data.type === 'product_carousel' && (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="s-limit">Product Limit</Label>
                        <Input
                            id="s-limit"
                            type="number"
                            min={1}
                            max={50}
                            value={data.settings.limit ?? 14}
                            onChange={(e) =>
                                setSetting('limit', parseInt(e.target.value))
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label>
                            Products ({data.settings.product_ids?.length ?? 0}{' '}
                            selected)
                        </Label>
                        <div className="max-h-64 space-y-2 overflow-y-auto rounded-md border p-3">
                            {products.map((product) => {
                                const selected = (
                                    data.settings.product_ids ?? []
                                ).includes(product.id);

                                return (
                                    <label
                                        key={product.id}
                                        className="flex cursor-pointer items-start gap-2"
                                    >
                                        <Checkbox
                                            checked={selected}
                                            onCheckedChange={(checked) => {
                                                const ids =
                                                    data.settings.product_ids ??
                                                    [];
                                                setSetting(
                                                    'product_ids',
                                                    checked
                                                        ? [...ids, product.id]
                                                        : ids.filter(
                                                              (id) =>
                                                                  id !==
                                                                  product.id,
                                                          ),
                                                );
                                            }}
                                        />
                                        <span className="text-sm">
                                            {product.name}
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                {product.sku}
                                            </span>
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                        <InputError message={errors['settings.product_ids']} />
                    </div>
                </>
            )}

            {data.type === 'catalog_showcase' && (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="s-eyebrow">Eyebrow text</Label>
                        <Input
                            id="s-eyebrow"
                            value={data.settings.eyebrow ?? ''}
                            onChange={(e) =>
                                setSetting('eyebrow', e.target.value)
                            }
                            placeholder="Gateway"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="s-headings">
                            Animated headings (one per line)
                        </Label>
                        <textarea
                            id="s-headings"
                            className="min-h-24 rounded-md border bg-background px-3 py-2 text-sm"
                            value={(data.settings.heading_phrases ?? []).join(
                                '\n',
                            )}
                            onChange={(e) =>
                                setSetting(
                                    'heading_phrases',
                                    e.target.value
                                        .split('\n')
                                        .map((value) => value.trim())
                                        .filter(Boolean),
                                )
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="s-description">Description</Label>
                        <textarea
                            id="s-description"
                            className="min-h-28 rounded-md border bg-background px-3 py-2 text-sm"
                            value={data.settings.description ?? ''}
                            onChange={(e) =>
                                setSetting('description', e.target.value)
                            }
                        />
                    </div>
                </>
            )}

            {data.type === 'brand_showcase' && (
                <div className="grid gap-2">
                    <Label>Brands</Label>
                    <div className="max-h-48 space-y-2 overflow-y-auto rounded-md border p-3">
                        {brands.map((brand) => {
                            const selected = (
                                data.settings.brand_ids ?? []
                            ).includes(brand.id);

                            return (
                                <label
                                    key={brand.id}
                                    className="flex cursor-pointer items-center gap-2"
                                >
                                    <Checkbox
                                        checked={selected}
                                        onCheckedChange={(checked) => {
                                            const ids =
                                                data.settings.brand_ids ?? [];
                                            setSetting(
                                                'brand_ids',
                                                checked
                                                    ? [...ids, brand.id]
                                                    : ids.filter(
                                                          (id) =>
                                                              id !== brand.id,
                                                      ),
                                            );
                                        }}
                                    />
                                    <span className="text-sm">
                                        {brand.name}
                                    </span>
                                </label>
                            );
                        })}
                        {brands.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No active brands found.
                            </p>
                        )}
                    </div>
                    <InputError message={errors['settings.brand_ids']} />
                </div>
            )}

            <div className="flex items-center gap-3">
                <Switch
                    id="s-active"
                    checked={data.is_active}
                    onCheckedChange={(v) => setData('is_active', v)}
                />
                <Label htmlFor="s-active">Active</Label>
            </div>
        </div>
    );
}

// ─── Main Page ─────────────────────────────────────────────────────────────────

function emptySectionForm(): SectionFormData {
    return { type: 'hero', title: '', is_active: true, settings: {} };
}

interface ItemFormData {
    kind: 'content' | 'heading' | 'slide' | 'brand' | 'catalog';
    title: string;
    alt_text: string;
    link_url: string;
    is_active: boolean;
    desktop_image: File | null;
    desktop_image_external_url: string;
    mobile_image: File | null;
    mobile_image_external_url: string;
    pdf_file: File | null;
}

function emptyItemForm(): ItemFormData {
    return {
        kind: 'slide',
        title: '',
        alt_text: '',
        link_url: '',
        is_active: true,
        desktop_image: null,
        desktop_image_external_url: '',
        mobile_image: null,
        mobile_image_external_url: '',
        pdf_file: null,
    };
}

export default function HomepageEdit({
    config,
    categories,
    brands,
    products,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Admin', href: adminRoutes.dashboard().url },
        { title: 'Homepage Configs', href: adminRoutes.homepage.index().url },
        { title: config.label, href: adminRoutes.homepage.edit(config.id).url },
    ];

    // Config settings form
    const siteSettings: SiteSettings = config.site_settings ?? {};
    const configForm = useForm({
        label: config.label,
        client_key: config.client_key,
        is_active: config.is_active,
        site_settings: {
            ...siteSettings,
            logo_url: externalUrlValue(siteSettings.logo_url),
            catalog_background_logo_url: externalUrlValue(
                siteSettings.catalog_background_logo_url,
            ),
        },
        site_logo: null as File | null,
        catalog_background_image: null as File | null,
        remove_site_logo: false,
        remove_catalog_background: false,
    });
    // Settings saved before keywords/links were always stored may lack them.
    const initialNavText = (siteSettings.nav_groups ?? [])
        .map(
            (group) =>
                `${group.label} | ${(group.keywords ?? []).join(', ')} | ${group.url ?? ''}${group.names?.length ? ` | ${group.names.join(', ')}` : ''}`,
        )
        .join('\n');
    const initialFooterText = (siteSettings.footer_columns ?? [])
        .flatMap((column) => [
            `[${column.title}]`,
            ...(column.links ?? []).map(
                (link) => `${link.label} | ${link.url}`,
            ),
        ])
        .join('\n');
    const initialHoursText = (siteSettings.business_hours ?? []).join('\n');
    const linksToText = (links?: { label: string; url: string }[]) =>
        (links ?? []).map((link) => `${link.label} | ${link.url}`).join('\n');
    const initialLinkTexts = {
        utility_links: linksToText(siteSettings.utility_links),
        nav_links_before: linksToText(siteSettings.nav_links_before),
        nav_links: linksToText(siteSettings.nav_links),
        all_categories: linksToText(siteSettings.all_categories),
    };
    const [linkTexts, setLinkTexts] = useState(initialLinkTexts);
    const [navText, setNavText] = useState(initialNavText);
    const [footerText, setFooterText] = useState(initialFooterText);
    const [hoursText, setHoursText] = useState(initialHoursText);
    const [openContentSections, setOpenContentSections] = useState<
        Record<number, boolean>
    >({});
    const [assetQueries, setAssetQueries] = useState<Record<number, string>>(
        {},
    );

    function setSiteSetting<K extends keyof SiteSettings>(
        key: K,
        value: SiteSettings[K],
    ) {
        configForm.setData('site_settings', {
            ...configForm.data.site_settings,
            [key]: value,
        });
    }

    function parseNavigation(value: string) {
        return value
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean)
            .map((line) => {
                const [label = '', keywords = '', url = '', names = ''] = line
                    .split('|')
                    .map((part) => part.trim());

                return {
                    label,
                    keywords: keywords
                        .split(',')
                        .map((keyword) => keyword.trim())
                        .filter(Boolean),
                    ...(url ? { url } : {}),
                    // Exact category names for the menu (kept in this order); empty = match by keywords.
                    ...(names
                        ? {
                              names: names
                                  .split(',')
                                  .map((name) => name.trim())
                                  .filter(Boolean),
                          }
                        : {}),
                };
            })
            .filter((group) => group.label);
    }

    function parseLinks(value: string) {
        return value
            .split('\n')
            .map((line) => line.split('|').map((part) => part.trim()))
            .filter(([label, url]) => label && url)
            .map(([label, url]) => ({ label, url }));
    }

    function parseFooter(value: string) {
        const columns: NonNullable<SiteSettings['footer_columns']> = [];
        value
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean)
            .forEach((line) => {
                if (line.startsWith('[') && line.endsWith(']')) {
                    columns.push({
                        title: line.slice(1, -1).trim(),
                        links: [],
                    });

                    return;
                }

                const [label = '', url = ''] = line
                    .split('|')
                    .map((part) => part.trim());

                if (columns.length && label && url) {
                    columns[columns.length - 1].links.push({ label, url });
                }
            });

        return columns;
    }

    function saveConfig(e: React.FormEvent) {
        e.preventDefault();
        configForm.transform((data) => ({
            ...data,
            _method: 'patch',
            site_settings: {
                ...data.site_settings,
                nav_groups: parseNavigation(navText),
                footer_columns: parseFooter(footerText),
                utility_links: parseLinks(linkTexts.utility_links),
                nav_links_before: parseLinks(linkTexts.nav_links_before),
                nav_links: parseLinks(linkTexts.nav_links),
                all_categories: parseLinks(linkTexts.all_categories),
                business_hours: hoursText
                    .split('\n')
                    .map((line) => line.trim())
                    .filter(Boolean),
            },
        }));
        configForm.post(adminRoutes.homepage.update(config.id).url, {
            forceFormData: true,
        });
    }

    // Section dialog state
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingSection, setEditingSection] =
        useState<HomepageSection | null>(null);
    const sectionForm = useForm<SectionFormData>(emptySectionForm());
    const [itemDialogOpen, setItemDialogOpen] = useState(false);
    const [itemSection, setItemSection] = useState<HomepageSection | null>(
        null,
    );
    const [editingItem, setEditingItem] = useState<HomepageSectionItem | null>(
        null,
    );
    const itemForm = useForm<ItemFormData>(emptyItemForm());
    const [brandDialogOpen, setBrandDialogOpen] = useState(false);
    const [brandSection, setBrandSection] = useState<HomepageSection | null>(
        null,
    );
    const brandForm = useForm<{ images: File[] }>({ images: [] });

    function openBrandUpload(section: HomepageSection) {
        brandForm.reset();
        brandForm.clearErrors();
        setBrandSection(section);
        setBrandDialogOpen(true);
    }

    function submitBrandLogos(e: React.FormEvent) {
        e.preventDefault();

        if (!brandSection) {
            return;
        }

        brandForm.post(
            `/admin/homepage/${config.id}/sections/${brandSection.id}/items/bulk-brands`,
            {
                forceFormData: true,
                onSuccess: () => {
                    setBrandDialogOpen(false);
                    brandForm.reset();
                },
            },
        );
    }

    function openAddItem(
        section: HomepageSection,
        kind: ItemFormData['kind'] = 'slide',
    ) {
        itemForm.setData({ ...emptyItemForm(), kind });
        itemForm.clearErrors();
        setItemSection(section);
        setEditingItem(null);
        setItemDialogOpen(true);
    }

    function openEditItem(section: HomepageSection, item: HomepageSectionItem) {
        itemForm.setData({
            kind: item.kind,
            title: item.title ?? '',
            alt_text: item.alt_text ?? '',
            link_url: item.link_url ?? '',
            is_active: item.is_active,
            desktop_image: null,
            desktop_image_external_url: item.desktop_image_external_url ?? '',
            mobile_image: null,
            mobile_image_external_url: item.mobile_image_external_url ?? '',
            pdf_file: null,
        });
        itemForm.clearErrors();
        setItemSection(section);
        setEditingItem(item);
        setItemDialogOpen(true);
    }

    function submitItem(e: React.FormEvent) {
        e.preventDefault();

        if (!itemSection) {
            return;
        }

        const baseUrl = `/admin/homepage/${config.id}/sections/${itemSection.id}/items`;
        const options = {
            forceFormData: true,
            onSuccess: () => {
                setItemDialogOpen(false);
                itemForm.reset();
            },
        };

        if (editingItem) {
            itemForm.post(`${baseUrl}/${editingItem.id}`, options);
        } else {
            itemForm.post(baseUrl, options);
        }
    }

    function deleteItem(section: HomepageSection, item: HomepageSectionItem) {
        if (!confirm(`Remove image "${item.title || item.alt_text}"?`)) {
            return;
        }

        router.delete(
            `/admin/homepage/${config.id}/sections/${section.id}/items/${item.id}`,
        );
    }

    function moveItem(
        section: HomepageSection,
        item: HomepageSectionItem,
        direction: 'up' | 'down',
    ) {
        const items = [...(section.items ?? [])].sort(
            (a, b) => a.sort_order - b.sort_order,
        );
        const idx = items.findIndex((entry) => entry.id === item.id);
        const swapIdx = direction === 'up' ? idx - 1 : idx + 1;

        if (swapIdx < 0 || swapIdx >= items.length) {
            return;
        }

        const reordered = items.map((entry, index) => ({
            id: entry.id,
            sort_order:
                index === idx
                    ? items[swapIdx].sort_order
                    : index === swapIdx
                      ? items[idx].sort_order
                      : entry.sort_order,
        }));
        router.post(
            `/admin/homepage/${config.id}/sections/${section.id}/items/reorder`,
            { items: reordered },
        );
    }

    function openAdd() {
        sectionForm.reset();
        sectionForm.setData(emptySectionForm());
        setEditingSection(null);
        setDialogOpen(true);
    }

    function openEdit(section: HomepageSection) {
        sectionForm.setData({
            type: section.type,
            title: section.title,
            is_active: section.is_active,
            settings: section.settings ?? {},
        });
        setEditingSection(section);
        setDialogOpen(true);
    }

    function submitSection(e: React.FormEvent) {
        e.preventDefault();

        if (editingSection) {
            sectionForm.patch(
                adminRoutes.homepage.sections.update({
                    config: config.id,
                    section: editingSection.id,
                }).url,
                {
                    onSuccess: () => setDialogOpen(false),
                },
            );
        } else {
            sectionForm.post(
                adminRoutes.homepage.sections.store(config.id).url,
                {
                    onSuccess: () => setDialogOpen(false),
                },
            );
        }
    }

    function deleteSection(section: HomepageSection) {
        if (!confirm(`Remove section "${section.title}"?`)) {
            return;
        }

        router.delete(
            adminRoutes.homepage.sections.destroy({
                config: config.id,
                section: section.id,
            }).url,
        );
    }

    function move(section: HomepageSection, direction: 'up' | 'down') {
        const sections = [...(config.sections ?? [])].sort(
            (a, b) => a.sort_order - b.sort_order,
        );
        const idx = sections.findIndex((s) => s.id === section.id);
        const swapIdx = direction === 'up' ? idx - 1 : idx + 1;

        if (swapIdx < 0 || swapIdx >= sections.length) {
            return;
        }

        const reordered = sections.map((s, i) => {
            if (i === idx) {
                return { id: s.id, sort_order: sections[swapIdx].sort_order };
            }

            if (i === swapIdx) {
                return { id: s.id, sort_order: sections[idx].sort_order };
            }

            return { id: s.id, sort_order: s.sort_order };
        });

        router.post(adminRoutes.homepage.sections.reorder(config.id).url, {
            sections: reordered,
        });
    }

    const sortedSections = [...(config.sections ?? [])].sort(
        (a, b) => a.sort_order - b.sort_order,
    );
    const activeSections = sortedSections.filter(
        (section) => section.is_active,
    ).length;
    const totalMedia = sortedSections.reduce(
        (total, section) => total + (section.items?.length ?? 0),
        0,
    );
    const hasUnsavedSiteChanges =
        configForm.isDirty ||
        navText !== initialNavText ||
        footerText !== initialFooterText ||
        hoursText !== initialHoursText ||
        JSON.stringify(linkTexts) !== JSON.stringify(initialLinkTexts);
    // Errors for fields that have no inline InputError (nested site settings).
    const inlineConfigErrorKeys = [
        'label',
        'client_key',
        'site_logo',
        'site_settings.logo_url',
        'catalog_background_image',
        'site_settings.catalog_background_logo_url',
    ];
    const otherConfigErrors = Object.entries(configForm.errors).filter(
        ([key]) => !inlineConfigErrorKeys.includes(key),
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${config.label} — Homepage Config`} />
            <div className="mx-auto flex w-full max-w-[1600px] flex-col gap-8 p-4 sm:p-6 lg:p-8">
                <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                    <div className="relative isolate overflow-hidden border-b bg-gradient-to-br from-slate-950 via-slate-900 to-slate-800 px-6 py-7 text-white sm:px-8">
                        <div className="pointer-events-none absolute -top-24 -right-20 -z-10 h-72 w-72 rounded-full bg-orange-500/20 blur-3xl" />
                        <div className="pointer-events-none absolute -bottom-28 left-1/3 -z-10 h-64 w-64 rounded-full bg-blue-500/20 blur-3xl" />
                        <div className="flex flex-col justify-between gap-5 md:flex-row md:items-end">
                            <div>
                                <div className="mb-3 flex flex-wrap items-center gap-2">
                                    <span className="rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-medium text-slate-200">
                                        Homepage workspace
                                    </span>
                                    <span
                                        className={`rounded-full px-3 py-1 text-xs font-semibold ${config.is_active ? 'bg-emerald-400/15 text-emerald-300' : 'bg-slate-400/15 text-slate-300'}`}
                                    >
                                        {config.is_active
                                            ? 'Live configuration'
                                            : 'Inactive configuration'}
                                    </span>
                                </div>
                                <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                                    {config.label}
                                </h1>
                                <p className="mt-2 max-w-2xl text-sm text-slate-300">
                                    Manage global settings, homepage sections
                                    and every visual asset from one workspace.
                                </p>
                            </div>
                            <div className="flex flex-col items-stretch gap-2 sm:items-end">
                                <div className="rounded-xl border border-white/10 bg-white/5 px-4 py-3 backdrop-blur-sm">
                                    <p className="text-[11px] font-semibold tracking-[0.18em] text-slate-400 uppercase">
                                        Client key
                                    </p>
                                    <code className="mt-1 block text-sm font-semibold text-white">
                                        {config.client_key}
                                    </code>
                                </div>
                                <a
                                    href={`/api/homepage?client=${encodeURIComponent(config.client_key)}`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center justify-center gap-2 rounded-lg border border-white/15 px-3 py-2 text-xs font-semibold text-slate-200 transition hover:bg-white/10 hover:text-white"
                                >
                                    Test live API{' '}
                                    <ExternalLink className="h-3.5 w-3.5" />
                                </a>
                            </div>
                        </div>
                    </div>
                    <div className="grid divide-y sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                        <div className="flex items-center gap-3 px-6 py-4">
                            <span className="rounded-xl bg-blue-500/10 p-2.5 text-blue-600 dark:text-blue-400">
                                <Layers3 className="h-5 w-5" />
                            </span>
                            <div>
                                <p className="text-2xl leading-none font-bold">
                                    {sortedSections.length}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Total sections
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center gap-3 px-6 py-4">
                            <span className="rounded-xl bg-emerald-500/10 p-2.5 text-emerald-600 dark:text-emerald-400">
                                <Globe2 className="h-5 w-5" />
                            </span>
                            <div>
                                <p className="text-2xl leading-none font-bold">
                                    {activeSections}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Published sections
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center gap-3 px-6 py-4">
                            <span className="rounded-xl bg-orange-500/10 p-2.5 text-orange-600 dark:text-orange-400">
                                <Images className="h-5 w-5" />
                            </span>
                            <div>
                                <p className="text-2xl leading-none font-bold">
                                    {totalMedia}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Managed assets
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Config settings */}
                <section>
                    <div className="mb-4 flex items-start gap-3">
                        <span className="rounded-xl border bg-card p-2.5 text-muted-foreground shadow-sm">
                            <Settings2 className="h-5 w-5" />
                        </span>
                        <div>
                            <h2 className="text-lg font-semibold">
                                Site configuration
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Brand, navigation, SEO, newsletter and footer
                                settings.
                            </p>
                        </div>
                    </div>
                    <form
                        onSubmit={saveConfig}
                        className="space-y-6 rounded-2xl border bg-card p-5 shadow-sm sm:p-7"
                    >
                        <nav
                            className="sticky top-3 z-10 flex gap-2 overflow-x-auto rounded-xl border bg-background/95 p-2 shadow-sm backdrop-blur"
                            aria-label="Site configuration sections"
                        >
                            {[
                                ['config-general', 'General'],
                                ['config-brand', 'Brand & contact'],
                                ['config-header', 'Header'],
                                ['config-seo', 'Homepage & SEO'],
                                ['config-newsletter', 'Newsletter'],
                                ['config-footer', 'Footer'],
                            ].map(([href, label]) => (
                                <a
                                    key={href}
                                    href={`#${href}`}
                                    className="rounded-lg px-3 py-2 text-xs font-semibold whitespace-nowrap text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                >
                                    {label}
                                </a>
                            ))}
                        </nav>

                        <div
                            id="config-general"
                            className="grid scroll-mt-24 gap-4 rounded-xl border bg-muted/20 p-4 md:grid-cols-2"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="label">Label</Label>
                                <Input
                                    id="label"
                                    value={configForm.data.label}
                                    onChange={(e) =>
                                        configForm.setData(
                                            'label',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                                <InputError message={configForm.errors.label} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="client_key">Client Key</Label>
                                <Input
                                    id="client_key"
                                    value={configForm.data.client_key}
                                    readOnly
                                    aria-readonly="true"
                                    className="bg-muted font-mono"
                                    required
                                />
                                <p className="text-xs text-muted-foreground">
                                    Permanent API identifier. Create a new
                                    configuration if a different client key is
                                    required.
                                </p>
                                <InputError
                                    message={configForm.errors.client_key}
                                />
                            </div>
                            <div className="flex items-center gap-3">
                                <Switch
                                    id="is_active"
                                    checked={configForm.data.is_active}
                                    onCheckedChange={(v) =>
                                        configForm.setData('is_active', v)
                                    }
                                />
                                <Label htmlFor="is_active">Active</Label>
                            </div>
                        </div>
                        <div
                            id="config-brand"
                            className="scroll-mt-24 rounded-xl border p-5"
                        >
                            <h3 className="mb-4 font-semibold">
                                Brand and contact
                            </h3>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label>Site name</Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .site_name ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'site_name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>
                                        Tagline (under the site name in the
                                        logo)
                                    </Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .site_tagline ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'site_tagline',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Upload site logo</Label>
                                    <Input
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp,image/gif"
                                        onChange={(e) => {
                                            configForm.setData(
                                                'site_logo',
                                                e.target.files?.[0] ?? null,
                                            );

                                            if (e.target.files?.[0]) {
                                                configForm.setData(
                                                    'remove_site_logo',
                                                    false,
                                                );
                                            }
                                        }}
                                    />
                                    <InputError
                                        message={configForm.errors.site_logo}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        PNG or WebP, maximum 5 MB. A new upload
                                        replaces the current logo.
                                    </p>
                                    {config.site_logo_path && (
                                        <div className="space-y-2 rounded-lg border bg-muted/30 p-2">
                                            <div className="flex items-center gap-3">
                                                <img
                                                    src={`/storage/${config.site_logo_path}`}
                                                    alt="Current site logo"
                                                    className="h-12 w-12 rounded-md bg-white object-contain"
                                                />
                                                <span className="min-w-0 truncate text-xs text-muted-foreground">
                                                    {config.site_logo_path}
                                                </span>
                                            </div>
                                            <label className="flex cursor-pointer items-center gap-2 text-xs font-medium text-destructive">
                                                <Checkbox
                                                    checked={
                                                        configForm.data
                                                            .remove_site_logo
                                                    }
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        configForm.setData(
                                                            'remove_site_logo',
                                                            checked === true,
                                                        )
                                                    }
                                                />
                                                Remove current logo when saving
                                            </label>
                                        </div>
                                    )}
                                </div>
                                <div className="grid gap-2">
                                    <Label>External logo URL (optional)</Label>
                                    <Input
                                        type="url"
                                        placeholder="https://cdn.example.com/logo.png"
                                        value={
                                            configForm.data.site_settings
                                                .logo_url ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'logo_url',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Use this only when no logo is uploaded.
                                        Uploaded files take priority.
                                    </p>
                                    <InputError
                                        message={
                                            configForm.errors[
                                                'site_settings.logo_url'
                                            ]
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Phone</Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .phone ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'phone',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Email</Label>
                                    <Input
                                        type="email"
                                        value={
                                            configForm.data.site_settings
                                                .email ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'email',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2 md:col-span-2">
                                    <Label>Address</Label>
                                    <textarea
                                        className="min-h-20 rounded-md border bg-background px-3 py-2 text-sm"
                                        value={
                                            configForm.data.site_settings
                                                .address ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'address',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        </div>

                        <div
                            id="config-header"
                            className="scroll-mt-24 rounded-xl border p-5"
                        >
                            <h3 className="mb-4 font-semibold">
                                Header and navigation
                            </h3>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label>Search placeholder</Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .search_placeholder ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'search_placeholder',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Login button text</Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .login_text ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'login_text',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Register button text</Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .register_text ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'register_text',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Sale label</Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .sale_label ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'sale_label',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Sale URL</Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .sale_url ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'sale_url',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2 md:col-span-2">
                                    <Label>Navigation groups</Label>
                                    <textarea
                                        className="min-h-44 rounded-md border bg-background px-3 py-2 font-mono text-xs"
                                        value={navText}
                                        onChange={(e) =>
                                            setNavText(e.target.value)
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        One per line: Label | keyword1, keyword2
                                        | optional URL | optional exact category
                                        names (comma separated, shown in this
                                        order)
                                    </p>
                                </div>
                                <div className="grid gap-2 md:col-span-2">
                                    <Label>Top bar message</Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .utility_message ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'utility_message',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                {(
                                    [
                                        ['utility_links', 'Top bar links'],
                                        [
                                            'nav_links_before',
                                            'Menu links before the groups (Home, …)',
                                        ],
                                        [
                                            'nav_links',
                                            'Menu links after the groups',
                                        ],
                                        [
                                            'all_categories',
                                            '“All Categories” menu (empty = every category)',
                                        ],
                                    ] as const
                                ).map(([key, label]) => (
                                    <div key={key} className="grid gap-2">
                                        <Label>{label}</Label>
                                        <textarea
                                            className="min-h-28 rounded-md border bg-background px-3 py-2 font-mono text-xs"
                                            value={linkTexts[key]}
                                            onChange={(e) =>
                                                setLinkTexts((texts) => ({
                                                    ...texts,
                                                    [key]: e.target.value,
                                                }))
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            One per line: Label | /url
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div
                            id="config-seo"
                            className="scroll-mt-24 rounded-xl border p-5"
                        >
                            <h3 className="mb-4 font-semibold">
                                Homepage, catalog and SEO
                            </h3>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label>SEO title</Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .seo_title ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'seo_title',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Accessible homepage heading</Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .homepage_heading ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'homepage_heading',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2 md:col-span-2">
                                    <Label>SEO description</Label>
                                    <textarea
                                        className="min-h-20 rounded-md border bg-background px-3 py-2 text-sm"
                                        value={
                                            configForm.data.site_settings
                                                .seo_description ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'seo_description',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Upload catalog background</Label>
                                    <Input
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp,image/gif"
                                        onChange={(e) => {
                                            configForm.setData(
                                                'catalog_background_image',
                                                e.target.files?.[0] ?? null,
                                            );

                                            if (e.target.files?.[0]) {
                                                configForm.setData(
                                                    'remove_catalog_background',
                                                    false,
                                                );
                                            }
                                        }}
                                    />
                                    <InputError
                                        message={
                                            configForm.errors
                                                .catalog_background_image
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        PNG or WebP, maximum 5 MB. A new upload
                                        replaces the current image.
                                    </p>
                                    {config.catalog_background_path && (
                                        <div className="space-y-2 rounded-lg border bg-muted/30 p-2">
                                            <div className="flex items-center gap-3">
                                                <img
                                                    src={`/storage/${config.catalog_background_path}`}
                                                    alt="Current catalog background"
                                                    className="h-12 w-20 rounded-md bg-white object-contain"
                                                />
                                                <span className="min-w-0 truncate text-xs text-muted-foreground">
                                                    {
                                                        config.catalog_background_path
                                                    }
                                                </span>
                                            </div>
                                            <label className="flex cursor-pointer items-center gap-2 text-xs font-medium text-destructive">
                                                <Checkbox
                                                    checked={
                                                        configForm.data
                                                            .remove_catalog_background
                                                    }
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        configForm.setData(
                                                            'remove_catalog_background',
                                                            checked === true,
                                                        )
                                                    }
                                                />
                                                Remove current background when
                                                saving
                                            </label>
                                        </div>
                                    )}
                                </div>
                                <div className="grid gap-2">
                                    <Label>
                                        External catalog background URL
                                        (optional)
                                    </Label>
                                    <Input
                                        type="url"
                                        placeholder="https://cdn.example.com/catalog-background.png"
                                        value={
                                            configForm.data.site_settings
                                                .catalog_background_logo_url ??
                                            ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'catalog_background_logo_url',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Use this only when no background image
                                        is uploaded.
                                    </p>
                                    <InputError
                                        message={
                                            configForm.errors[
                                                'site_settings.catalog_background_logo_url'
                                            ]
                                        }
                                    />
                                </div>
                            </div>
                        </div>

                        <div
                            id="config-newsletter"
                            className="scroll-mt-24 rounded-xl border p-5"
                        >
                            <h3 className="mb-4 font-semibold">Newsletter</h3>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="flex items-center gap-3 md:col-span-2">
                                    <Switch
                                        checked={
                                            configForm.data.site_settings
                                                .newsletter_enabled ?? false
                                        }
                                        onCheckedChange={(value) =>
                                            setSiteSetting(
                                                'newsletter_enabled',
                                                value,
                                            )
                                        }
                                    />
                                    <Label>Show newsletter section</Label>
                                </div>
                                <div className="grid gap-2">
                                    <Label>Title</Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .newsletter_title ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'newsletter_title',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Input placeholder</Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .newsletter_placeholder ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'newsletter_placeholder',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Button text</Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .newsletter_button_text ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'newsletter_button_text',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        </div>

                        <div
                            id="config-footer"
                            className="scroll-mt-24 rounded-xl border p-5"
                        >
                            <h3 className="mb-4 font-semibold">Footer</h3>
                            <div className="space-y-4">
                                <div className="grid gap-2">
                                    <Label>Footer columns</Label>
                                    <textarea
                                        className="min-h-52 rounded-md border bg-background px-3 py-2 font-mono text-xs"
                                        value={footerText}
                                        onChange={(e) =>
                                            setFooterText(e.target.value)
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Use [Column title], then one Link label
                                        | URL per line.
                                    </p>
                                </div>
                                <div className="grid gap-2">
                                    <Label>Business hours (one per line)</Label>
                                    <textarea
                                        className="min-h-36 rounded-md border bg-background px-3 py-2 text-sm"
                                        value={hoursText}
                                        onChange={(e) =>
                                            setHoursText(e.target.value)
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Business hours heading</Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .business_hours_title ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'business_hours_title',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Copyright text</Label>
                                    <Input
                                        value={
                                            configForm.data.site_settings
                                                .footer_copyright ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'footer_copyright',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>Legal / warning notice</Label>
                                    <textarea
                                        className="min-h-28 rounded-md border bg-background px-3 py-2 text-sm"
                                        value={
                                            configForm.data.site_settings
                                                .footer_legal_notice ?? ''
                                        }
                                        onChange={(e) =>
                                            setSiteSetting(
                                                'footer_legal_notice',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        </div>
                        {otherConfigErrors.length > 0 && (
                            <div
                                role="alert"
                                className="rounded-xl border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
                            >
                                <p className="font-semibold">
                                    Site settings were not saved:
                                </p>
                                <ul className="mt-2 list-disc space-y-1 pl-5">
                                    {otherConfigErrors.map(([key, message]) => (
                                        <li key={key}>{message}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        <div className="sticky bottom-4 z-10 flex items-center justify-between gap-4 rounded-xl border bg-background/95 p-3 shadow-lg backdrop-blur supports-[backdrop-filter]:bg-background/85">
                            <p
                                className={`hidden text-xs sm:block ${hasUnsavedSiteChanges ? 'font-medium text-amber-600 dark:text-amber-400' : 'text-muted-foreground'}`}
                            >
                                {hasUnsavedSiteChanges
                                    ? 'Unsaved site configuration changes'
                                    : 'All site configuration changes are saved'}
                            </p>
                            <Button
                                type="submit"
                                disabled={
                                    configForm.processing ||
                                    !hasUnsavedSiteChanges
                                }
                                className="ml-auto min-w-44"
                            >
                                <Save className="mr-2 h-4 w-4" />
                                {configForm.processing
                                    ? 'Saving...'
                                    : 'Save site settings'}
                            </Button>
                        </div>
                    </form>
                </section>

                {/* Sections */}
                <section>
                    <div className="mb-4 flex items-end justify-between gap-4">
                        <div>
                            <h2 className="text-lg font-semibold">
                                Page structure
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Control publishing order and section-level
                                behaviour.
                            </p>
                        </div>
                        <Button size="sm" onClick={openAdd}>
                            <Plus className="mr-1 h-4 w-4" /> Add Section
                        </Button>
                    </div>

                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-8">#</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Title</TableHead>
                                    <TableHead>Settings</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="w-32" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sortedSections.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            No sections yet. Click "Add Section"
                                            to get started.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {sortedSections.map((section, idx) => (
                                    <TableRow key={section.id}>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {idx + 1}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                className={sectionBadgeColor(
                                                    section.type,
                                                )}
                                            >
                                                {SECTION_LABELS[section.type]}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {section.title}
                                        </TableCell>
                                        <TableCell className="max-w-xs truncate text-sm text-muted-foreground">
                                            {settingsSummary(
                                                section,
                                                categories,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {section.is_active ? (
                                                <Badge className="border-green-200 bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400">
                                                    Active
                                                </Badge>
                                            ) : (
                                                <Badge variant="secondary">
                                                    Inactive
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center justify-end gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-7 w-7"
                                                    disabled={idx === 0}
                                                    onClick={() =>
                                                        move(section, 'up')
                                                    }
                                                    title="Move up"
                                                >
                                                    <ChevronUp className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-7 w-7"
                                                    disabled={
                                                        idx ===
                                                        sortedSections.length -
                                                            1
                                                    }
                                                    onClick={() =>
                                                        move(section, 'down')
                                                    }
                                                    title="Move down"
                                                >
                                                    <ChevronDown className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-7 w-7"
                                                    onClick={() =>
                                                        openEdit(section)
                                                    }
                                                    title="Edit"
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-7 w-7"
                                                    onClick={() =>
                                                        deleteSection(section)
                                                    }
                                                    title="Delete"
                                                >
                                                    <Trash2 className="h-4 w-4 text-destructive" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </section>

                <section>
                    <div className="mb-4 flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
                        <div>
                            <h2 className="text-lg font-semibold">
                                Section content
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Open only the section you need, then search,
                                upload and arrange its assets.
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setOpenContentSections(
                                        Object.fromEntries(
                                            sortedSections.map((section) => [
                                                section.id,
                                                true,
                                            ]),
                                        ),
                                    )
                                }
                            >
                                Expand all
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => setOpenContentSections({})}
                            >
                                Collapse all
                            </Button>
                        </div>
                    </div>
                    <div className="space-y-4">
                        {sortedSections.map((section) => {
                            const items = [...(section.items ?? [])].sort(
                                (a, b) => a.sort_order - b.sort_order,
                            );
                            const isOpen =
                                openContentSections[section.id] ?? false;
                            const query = (assetQueries[section.id] ?? '')
                                .trim()
                                .toLowerCase();
                            const filteredItems = query
                                ? items.filter((item) =>
                                      [
                                          item.title,
                                          item.alt_text,
                                          item.kind,
                                      ].some((value) =>
                                          value?.toLowerCase().includes(query),
                                      ),
                                  )
                                : items;
                            const activeItems = items.filter(
                                (item) => item.is_active,
                            ).length;

                            return (
                                <Collapsible
                                    key={section.id}
                                    open={isOpen}
                                    onOpenChange={(open) =>
                                        setOpenContentSections((current) => ({
                                            ...current,
                                            [section.id]: open,
                                        }))
                                    }
                                    className="overflow-hidden rounded-2xl border bg-card shadow-sm transition-shadow hover:shadow-md"
                                >
                                    <div className="flex flex-col justify-between gap-3 p-4 sm:flex-row sm:items-center sm:p-5">
                                        <div className="min-w-0">
                                            <div className="mb-1 flex flex-wrap items-center gap-2">
                                                <h3 className="font-semibold">
                                                    {section.title}
                                                </h3>
                                                <Badge
                                                    className={sectionBadgeColor(
                                                        section.type,
                                                    )}
                                                >
                                                    {
                                                        SECTION_LABELS[
                                                            section.type
                                                        ]
                                                    }
                                                </Badge>
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                {items.length} asset
                                                {items.length === 1
                                                    ? ''
                                                    : 's'}{' '}
                                                · {activeItems} published
                                            </p>
                                        </div>
                                        <CollapsibleTrigger asChild>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                className="min-w-32"
                                            >
                                                {isOpen ? (
                                                    <ChevronUp className="mr-1 h-4 w-4" />
                                                ) : (
                                                    <ChevronDown className="mr-1 h-4 w-4" />
                                                )}
                                                {isOpen
                                                    ? 'Close assets'
                                                    : 'Manage assets'}
                                            </Button>
                                        </CollapsibleTrigger>
                                    </div>
                                    <CollapsibleContent className="border-t">
                                        <div className="space-y-4 p-4 sm:p-5">
                                            <div className="flex flex-col justify-between gap-3 lg:flex-row lg:items-center">
                                                <div className="relative w-full lg:max-w-sm">
                                                    <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                                    <Input
                                                        value={
                                                            assetQueries[
                                                                section.id
                                                            ] ?? ''
                                                        }
                                                        onChange={(event) =>
                                                            setAssetQueries(
                                                                (current) => ({
                                                                    ...current,
                                                                    [section.id]:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                }),
                                                            )
                                                        }
                                                        placeholder="Search title, alt text or type"
                                                        className="pl-9"
                                                    />
                                                </div>
                                                <div className="flex flex-wrap gap-2">
                                                    {![
                                                        'hero',
                                                        'banner',
                                                        'catalog_showcase',
                                                    ].includes(
                                                        section.type,
                                                    ) && (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            onClick={() =>
                                                                openAddItem(
                                                                    section,
                                                                    'heading',
                                                                )
                                                            }
                                                        >
                                                            <ImagePlus className="mr-1 h-4 w-4" />{' '}
                                                            Upload Heading
                                                            Banner
                                                        </Button>
                                                    )}
                                                    {section.type ===
                                                    'brand_showcase' ? (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                openBrandUpload(
                                                                    section,
                                                                )
                                                            }
                                                        >
                                                            <ImagePlus className="mr-1 h-4 w-4" />{' '}
                                                            Upload Brand Logos
                                                            (max 14)
                                                        </Button>
                                                    ) : (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                openAddItem(
                                                                    section,
                                                                    section.type ===
                                                                        'hero'
                                                                        ? 'slide'
                                                                        : section.type ===
                                                                            'catalog_showcase'
                                                                          ? 'catalog'
                                                                          : 'content',
                                                                )
                                                            }
                                                        >
                                                            <Plus className="mr-1 h-4 w-4" />{' '}
                                                            {section.type ===
                                                            'catalog_showcase'
                                                                ? 'Add Catalog'
                                                                : section.type ===
                                                                    'hero'
                                                                  ? 'Add Slide'
                                                                  : 'Add Content Image'}
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                            {items.length === 0 ? (
                                                <p className="rounded-md border border-dashed p-5 text-center text-sm text-muted-foreground">
                                                    No images uploaded.
                                                </p>
                                            ) : filteredItems.length === 0 ? (
                                                <p className="rounded-md border border-dashed p-5 text-center text-sm text-muted-foreground">
                                                    No assets match “
                                                    {assetQueries[section.id]}”.
                                                </p>
                                            ) : (
                                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                                    {filteredItems.map(
                                                        (item) => {
                                                            const itemIndex =
                                                                items.findIndex(
                                                                    (
                                                                        candidate,
                                                                    ) =>
                                                                        candidate.id ===
                                                                        item.id,
                                                                );

                                                            return (
                                                                <div
                                                                    key={
                                                                        item.id
                                                                    }
                                                                    className="group overflow-hidden rounded-xl border bg-background shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md"
                                                                >
                                                                    <div className="aspect-[16/7] bg-muted">
                                                                        <img
                                                                            src={
                                                                                item.desktop_image_path
                                                                                    ? `/storage/${item.desktop_image_path}`
                                                                                    : item.desktop_image_external_url ||
                                                                                      ''
                                                                            }
                                                                            alt={
                                                                                item.alt_text ||
                                                                                item.title ||
                                                                                ''
                                                                            }
                                                                            loading="lazy"
                                                                            className="h-full w-full object-contain transition-transform duration-300 group-hover:scale-[1.02]"
                                                                        />
                                                                    </div>
                                                                    <div className="space-y-2 p-3">
                                                                        <div className="flex items-start justify-between gap-2">
                                                                            <div className="min-w-0">
                                                                                <Badge variant="secondary">
                                                                                    {
                                                                                        item.kind
                                                                                    }
                                                                                </Badge>
                                                                                <p className="mt-1 line-clamp-1 text-sm font-medium">
                                                                                    {item.title ||
                                                                                        item.alt_text ||
                                                                                        'Untitled image'}
                                                                                </p>
                                                                                {item.pdf_path && (
                                                                                    <a
                                                                                        className="mt-1 block text-xs text-blue-600 underline"
                                                                                        href={`/storage/${item.pdf_path}`}
                                                                                        target="_blank"
                                                                                        rel="noreferrer"
                                                                                    >
                                                                                        View
                                                                                        PDF
                                                                                    </a>
                                                                                )}
                                                                            </div>
                                                                            <Badge
                                                                                className={
                                                                                    item.is_active
                                                                                        ? 'bg-green-100 text-green-800'
                                                                                        : ''
                                                                                }
                                                                                variant={
                                                                                    item.is_active
                                                                                        ? 'default'
                                                                                        : 'secondary'
                                                                                }
                                                                            >
                                                                                {item.is_active
                                                                                    ? 'Active'
                                                                                    : 'Hidden'}
                                                                            </Badge>
                                                                        </div>
                                                                        <div className="flex justify-end gap-1">
                                                                            <Button
                                                                                type="button"
                                                                                variant="ghost"
                                                                                size="icon"
                                                                                className="h-7 w-7"
                                                                                disabled={
                                                                                    itemIndex ===
                                                                                    0
                                                                                }
                                                                                onClick={() =>
                                                                                    moveItem(
                                                                                        section,
                                                                                        item,
                                                                                        'up',
                                                                                    )
                                                                                }
                                                                            >
                                                                                <ChevronUp className="h-4 w-4" />
                                                                            </Button>
                                                                            <Button
                                                                                type="button"
                                                                                variant="ghost"
                                                                                size="icon"
                                                                                className="h-7 w-7"
                                                                                disabled={
                                                                                    itemIndex ===
                                                                                    items.length -
                                                                                        1
                                                                                }
                                                                                onClick={() =>
                                                                                    moveItem(
                                                                                        section,
                                                                                        item,
                                                                                        'down',
                                                                                    )
                                                                                }
                                                                            >
                                                                                <ChevronDown className="h-4 w-4" />
                                                                            </Button>
                                                                            <Button
                                                                                type="button"
                                                                                variant="ghost"
                                                                                size="icon"
                                                                                className="h-7 w-7"
                                                                                onClick={() =>
                                                                                    openEditItem(
                                                                                        section,
                                                                                        item,
                                                                                    )
                                                                                }
                                                                            >
                                                                                <Pencil className="h-4 w-4" />
                                                                            </Button>
                                                                            <Button
                                                                                type="button"
                                                                                variant="ghost"
                                                                                size="icon"
                                                                                className="h-7 w-7"
                                                                                onClick={() =>
                                                                                    deleteItem(
                                                                                        section,
                                                                                        item,
                                                                                    )
                                                                                }
                                                                            >
                                                                                <Trash2 className="h-4 w-4 text-destructive" />
                                                                            </Button>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            );
                                                        },
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    </CollapsibleContent>
                                </Collapsible>
                            );
                        })}
                    </div>
                </section>
            </div>

            {/* Section add/edit dialog */}
            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {editingSection ? 'Edit Section' : 'Add Section'}
                        </DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitSection}>
                        <SectionFields
                            data={sectionForm.data}
                            setData={(key, value) =>
                                sectionForm.setData(key, value as never)
                            }
                            errors={sectionForm.errors}
                            categories={categories}
                            brands={brands}
                            products={products}
                        />
                        <DialogFooter className="mt-6">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setDialogOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={sectionForm.processing}
                            >
                                {editingSection
                                    ? 'Save Changes'
                                    : 'Add Section'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={itemDialogOpen} onOpenChange={setItemDialogOpen}>
                <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {editingItem ? 'Edit Image' : 'Upload Image'}
                            {itemSection ? ` — ${itemSection.title}` : ''}
                        </DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitItem} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="item-kind">Image Role</Label>
                            <Select
                                value={itemForm.data.kind}
                                onValueChange={(value) =>
                                    itemForm.setData(
                                        'kind',
                                        value as ItemFormData['kind'],
                                    )
                                }
                            >
                                <SelectTrigger id="item-kind">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="slide">
                                        Slider image
                                    </SelectItem>
                                    <SelectItem value="heading">
                                        Heading image
                                    </SelectItem>
                                    <SelectItem value="brand">
                                        Brand image
                                    </SelectItem>
                                    <SelectItem value="content">
                                        Content image
                                    </SelectItem>
                                    <SelectItem value="catalog">
                                        Catalog cover + PDF
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={itemForm.errors.kind} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="item-title">Internal title</Label>
                            <Input
                                id="item-title"
                                value={itemForm.data.title}
                                onChange={(e) =>
                                    itemForm.setData('title', e.target.value)
                                }
                            />
                            <InputError message={itemForm.errors.title} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="item-alt">
                                Alt text (optional)
                            </Label>
                            <Input
                                id="item-alt"
                                value={itemForm.data.alt_text}
                                onChange={(e) =>
                                    itemForm.setData('alt_text', e.target.value)
                                }
                                placeholder="Describe the image for accessibility"
                            />
                            <p className="text-xs text-muted-foreground">
                                If blank, the internal title is used
                                automatically.
                            </p>
                            <InputError message={itemForm.errors.alt_text} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="item-link">
                                Click URL (optional)
                            </Label>
                            <Input
                                id="item-link"
                                value={itemForm.data.link_url}
                                onChange={(e) =>
                                    itemForm.setData('link_url', e.target.value)
                                }
                            />
                            <InputError message={itemForm.errors.link_url} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="desktop-image">
                                Desktop image upload{' '}
                                {editingItem
                                    ? '(leave blank to keep current)'
                                    : '(upload or URL required)'}
                            </Label>
                            <Input
                                id="desktop-image"
                                type="file"
                                accept="image/png,image/jpeg,image/webp,image/gif"
                                onChange={(e) =>
                                    itemForm.setData(
                                        'desktop_image',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            <InputError
                                message={itemForm.errors.desktop_image}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="desktop-image-url">
                                Or desktop image URL
                            </Label>
                            <Input
                                id="desktop-image-url"
                                type="url"
                                placeholder="https://example.com/banner.jpg"
                                value={itemForm.data.desktop_image_external_url}
                                onChange={(e) =>
                                    itemForm.setData(
                                        'desktop_image_external_url',
                                        e.target.value,
                                    )
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                If a file and URL are both provided, the
                                uploaded file is used.
                            </p>
                            <InputError
                                message={
                                    itemForm.errors.desktop_image_external_url
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="mobile-image">
                                Mobile image upload (optional)
                            </Label>
                            <Input
                                id="mobile-image"
                                type="file"
                                accept="image/png,image/jpeg,image/webp,image/gif"
                                onChange={(e) =>
                                    itemForm.setData(
                                        'mobile_image',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            <InputError
                                message={itemForm.errors.mobile_image}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="mobile-image-url">
                                Or mobile image URL (optional)
                            </Label>
                            <Input
                                id="mobile-image-url"
                                type="url"
                                placeholder="https://example.com/banner-mobile.jpg"
                                value={itemForm.data.mobile_image_external_url}
                                onChange={(e) =>
                                    itemForm.setData(
                                        'mobile_image_external_url',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={
                                    itemForm.errors.mobile_image_external_url
                                }
                            />
                        </div>
                        {itemSection?.type === 'catalog_showcase' && (
                            <div className="grid gap-2">
                                <Label htmlFor="pdf-file">
                                    Catalog PDF{' '}
                                    {editingItem
                                        ? '(leave blank to keep current)'
                                        : ''}
                                </Label>
                                <Input
                                    id="pdf-file"
                                    type="file"
                                    accept="application/pdf"
                                    onChange={(e) =>
                                        itemForm.setData(
                                            'pdf_file',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                    required={!editingItem}
                                />
                                <p className="text-xs text-muted-foreground">
                                    PDF only, maximum 50 MB.
                                </p>
                                <InputError
                                    message={itemForm.errors.pdf_file}
                                />
                            </div>
                        )}
                        <div className="flex items-center gap-3">
                            <Switch
                                id="item-active"
                                checked={itemForm.data.is_active}
                                onCheckedChange={(value) =>
                                    itemForm.setData('is_active', value)
                                }
                            />
                            <Label htmlFor="item-active">Active</Label>
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setItemDialogOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={itemForm.processing}
                            >
                                {editingItem ? 'Save Image' : 'Upload Image'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={brandDialogOpen} onOpenChange={setBrandDialogOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Upload Top Brand Logos</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitBrandLogos} className="space-y-4">
                        <div className="rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
                            Select up to 14 logo images together. This replaces
                            the current brand-logo set. The website shows 7
                            logos per row and no more than 2 rows.
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="brand-images">
                                Brand logo images
                            </Label>
                            <Input
                                id="brand-images"
                                type="file"
                                multiple
                                accept="image/png,image/jpeg,image/webp,image/gif"
                                onChange={(event) =>
                                    brandForm.setData(
                                        'images',
                                        Array.from(
                                            event.target.files ?? [],
                                        ).slice(0, 14),
                                    )
                                }
                                required
                            />
                            <p className="text-xs text-muted-foreground">
                                {brandForm.data.images.length}/14 images
                                selected. PNG or WebP with a clean/transparent
                                background is recommended.
                            </p>
                            <InputError message={brandForm.errors.images} />
                            {Object.entries(brandForm.errors)
                                .filter(([key]) => key.startsWith('images.'))
                                .map(([key, message]) => (
                                    <InputError
                                        key={key}
                                        message={`${brandForm.data.images[Number(key.split('.')[1])]?.name ?? key}: ${message}`}
                                    />
                                ))}
                        </div>
                        {brandForm.data.images.length > 0 && (
                            <div className="max-h-40 overflow-y-auto rounded-md border p-3 text-xs">
                                {brandForm.data.images.map((file, index) => (
                                    <p key={`${file.name}-${index}`}>
                                        {index + 1}. {file.name}
                                    </p>
                                ))}
                            </div>
                        )}
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setBrandDialogOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    brandForm.processing ||
                                    brandForm.data.images.length === 0 ||
                                    brandForm.data.images.length > 14
                                }
                            >
                                Upload {brandForm.data.images.length || ''}{' '}
                                Logos
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

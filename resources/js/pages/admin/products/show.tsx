import { Head, Link, router } from '@inertiajs/react';
import { Heart, Star } from 'lucide-react';
import { PageHeader } from '@/components/admin/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import type { AdminProduct } from '@/types/admin';

function typeBadge(type: string) {
    const map: Record<string, string> = {
        simple: 'bg-blue-100 text-blue-800 border-blue-200',
        variable: 'bg-purple-100 text-purple-800 border-purple-200',
        grouped: 'bg-orange-100 text-orange-800 border-orange-200',
        digital: 'bg-teal-100 text-teal-800 border-teal-200',
    };

    return <Badge className={map[type] ?? ''}>{type}</Badge>;
}

export default function ProductShow({
    product,
    wishlistCount,
}: {
    product: AdminProduct;
    wishlistCount: number;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Admin', href: adminRoutes.dashboard().url },
        { title: 'Products', href: adminRoutes.products.index().url },
        {
            title: product.name,
            href: adminRoutes.products.show(product.id).url,
        },
    ];

    const isOnSale =
        product.sale_price !== null &&
        product.regular_price !== null &&
        parseFloat(product.sale_price) < parseFloat(product.regular_price);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${product.name} — Admin`} />
            <div className="flex flex-col gap-6 p-6">
                <PageHeader
                    title={product.name}
                    action={
                        <div className="flex items-center gap-2">
                            <Button
                                variant={
                                    product.is_featured ? 'default' : 'outline'
                                }
                                size="sm"
                                onClick={() =>
                                    router.patch(
                                        adminRoutes.products.featured(
                                            product.id,
                                        ).url,
                                        { is_featured: !product.is_featured },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Star
                                    className={
                                        product.is_featured
                                            ? 'fill-current'
                                            : ''
                                    }
                                />
                                {product.is_featured
                                    ? 'Featured'
                                    : 'Mark as featured'}
                            </Button>
                            {typeBadge(product.type)}
                            {product.is_active ? (
                                <Badge className="border-green-200 bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400">
                                    Active
                                </Badge>
                            ) : (
                                <Badge variant="secondary">Inactive</Badge>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        {/* Core Details */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Product Details</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <Row label="SKU" value={product.sku} />
                                <Row
                                    label="ERP Product ID"
                                    value={product.erp_product_id ?? '—'}
                                />
                                <Row
                                    label="Category"
                                    value={product.category?.name ?? '—'}
                                />
                                {product.sub_category && (
                                    <Row
                                        label="Sub-category"
                                        value={product.sub_category.name}
                                    />
                                )}
                                <Row
                                    label="Brand"
                                    value={product.brand?.name ?? '—'}
                                />
                                {product.parent && (
                                    <div className="flex items-center gap-2">
                                        <span className="w-32 shrink-0 text-sm text-muted-foreground">
                                            Parent Product
                                        </span>
                                        <Link
                                            href={
                                                adminRoutes.products.show(
                                                    product.parent.id,
                                                ).url
                                            }
                                            className="text-sm hover:underline"
                                        >
                                            {product.parent.name}
                                        </Link>
                                    </div>
                                )}
                                <Row
                                    label="Last Synced"
                                    value={
                                        product.synced_at
                                            ? new Date(
                                                  product.synced_at,
                                              ).toLocaleString()
                                            : '—'
                                    }
                                />
                            </CardContent>
                        </Card>

                        {/* Description */}
                        {(product.description || product.short_description) && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Description</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {product.short_description && (
                                        <div>
                                            <p className="mb-1 text-xs font-medium text-muted-foreground">
                                                Short Description
                                            </p>
                                            <p className="text-sm">
                                                {product.short_description}
                                            </p>
                                        </div>
                                    )}
                                    {product.description && (
                                        <div>
                                            <p className="mb-1 text-xs font-medium text-muted-foreground">
                                                Full Description
                                            </p>
                                            <div
                                                className="prose prose-sm max-w-none text-sm"
                                                dangerouslySetInnerHTML={{
                                                    __html: product.description,
                                                }}
                                            />
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Attributes */}
                        {product.attributes &&
                            Object.keys(product.attributes).length > 0 && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Attributes</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-2">
                                            {Object.entries(
                                                product.attributes,
                                            ).map(([key, val]) => (
                                                <div
                                                    key={key}
                                                    className="flex gap-2 text-sm"
                                                >
                                                    <span className="w-32 text-muted-foreground capitalize">
                                                        {key}
                                                    </span>
                                                    <span>{String(val)}</span>
                                                </div>
                                            ))}
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                        {/* Variants */}
                        {(product.children?.length ?? 0) > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        Variants ({product.children!.length})
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="p-0">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Image</TableHead>
                                                <TableHead>Name</TableHead>
                                                <TableHead>SKU</TableHead>
                                                <TableHead className="text-right">
                                                    Price
                                                </TableHead>
                                                <TableHead>Stock</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead />
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {product.children!.map((child) => (
                                                <TableRow key={child.id}>
                                                    <TableCell>
                                                        {child.primary_image
                                                            ?.public_url ? (
                                                            <img
                                                                src={
                                                                    child
                                                                        .primary_image
                                                                        .public_url
                                                                }
                                                                alt={child.name}
                                                                className="h-8 w-8 rounded object-cover"
                                                            />
                                                        ) : (
                                                            <div className="h-8 w-8 rounded bg-muted" />
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-sm">
                                                        {child.name}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-sm text-muted-foreground">
                                                        {child.sku}
                                                    </TableCell>
                                                    <TableCell className="text-right text-sm">
                                                        {child.regular_price ??
                                                            '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {child.in_stock ? (
                                                            <Badge className="border-green-200 bg-green-100 text-xs text-green-800">
                                                                In Stock
                                                            </Badge>
                                                        ) : (
                                                            <Badge
                                                                variant="destructive"
                                                                className="text-xs"
                                                            >
                                                                Out
                                                            </Badge>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {child.is_active ? (
                                                            <Badge
                                                                variant="secondary"
                                                                className="text-xs"
                                                            >
                                                                Active
                                                            </Badge>
                                                        ) : (
                                                            <Badge
                                                                variant="outline"
                                                                className="text-xs"
                                                            >
                                                                Inactive
                                                            </Badge>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Button
                                                            asChild
                                                            variant="ghost"
                                                            size="sm"
                                                        >
                                                            <Link
                                                                href={
                                                                    adminRoutes.products.show(
                                                                        child.id,
                                                                    ).url
                                                                }
                                                            >
                                                                View
                                                            </Link>
                                                        </Button>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    <div className="space-y-6">
                        {/* Wishlist Stats */}
                        <Card className="border-rose-200 dark:border-rose-900">
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Heart className="h-4 w-4 text-rose-500" />
                                    Wishlist
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-3xl font-bold">
                                    {wishlistCount}
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {wishlistCount === 1
                                        ? 'user has'
                                        : 'users have'}{' '}
                                    wishlisted this product
                                </p>
                            </CardContent>
                        </Card>

                        {/* Pricing */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Pricing</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <Row
                                    label="Regular Price"
                                    value={product.regular_price ?? '—'}
                                />
                                <Row
                                    label="Sale Price"
                                    value={product.sale_price ?? '—'}
                                />
                                {isOnSale && (
                                    <Badge className="border-red-200 bg-red-100 text-red-800">
                                        On Sale
                                    </Badge>
                                )}
                            </CardContent>
                        </Card>

                        {/* Stock */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Stock</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="flex items-center gap-2">
                                    <span className="w-28 shrink-0 text-sm text-muted-foreground">
                                        In Stock
                                    </span>
                                    {product.in_stock ? (
                                        <Badge className="border-green-200 bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400">
                                            Yes
                                        </Badge>
                                    ) : (
                                        <Badge variant="destructive">No</Badge>
                                    )}
                                </div>
                                <Row
                                    label="Manage Stock"
                                    value={product.manage_stock ? 'Yes' : 'No'}
                                />
                                {product.manage_stock && (
                                    <Row
                                        label="Quantity"
                                        value={
                                            product.stock_quantity !== null
                                                ? String(product.stock_quantity)
                                                : '—'
                                        }
                                    />
                                )}
                            </CardContent>
                        </Card>

                        {/* Gallery */}
                        {(product.media?.length ?? 0) > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        Images ({product.media!.length})
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid grid-cols-2 gap-2">
                                        {product.media!.map((m) => (
                                            <div
                                                key={m.id}
                                                className="relative"
                                            >
                                                <img
                                                    src={
                                                        m.public_url ??
                                                        m.url ??
                                                        ''
                                                    }
                                                    alt={m.alt ?? ''}
                                                    className="aspect-square w-full rounded bg-muted object-cover"
                                                />
                                                {m.is_primary && (
                                                    <Badge className="absolute top-1 left-1 text-xs">
                                                        Primary
                                                    </Badge>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-start gap-2">
            <span className="w-32 shrink-0 text-sm text-muted-foreground">
                {label}
            </span>
            <span className="text-sm">{value}</span>
        </div>
    );
}

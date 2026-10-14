import { Head, Link, router } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { DataTablePagination } from '@/components/admin/data-table';
import { PageHeader } from '@/components/admin/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
import type { AdminProduct, Paginated } from '@/types/admin';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: adminRoutes.dashboard().url },
    { title: 'Products', href: adminRoutes.products.index().url },
];

interface Filters extends Record<string, string | number | undefined> {
    search?: string;
    category_id?: string;
    brand_id?: string;
    type?: string;
    in_stock?: string;
    is_active?: string;
    is_featured?: string;
    best_seller?: string;
    per_page?: string;
}

function typeBadge(type: string) {
    const map: Record<string, string> = {
        simple: 'bg-blue-100 text-blue-800 border-blue-200',
        variable: 'bg-purple-100 text-purple-800 border-purple-200',
        grouped: 'bg-orange-100 text-orange-800 border-orange-200',
        digital: 'bg-teal-100 text-teal-800 border-teal-200',
    };

    return <Badge className={map[type] ?? ''}>{type}</Badge>;
}

export default function ProductsIndex({
    products,
    categories,
    brands,
    filters,
}: {
    products: Paginated<AdminProduct>;
    categories: { id: number; name: string }[];
    brands: { id: number; name: string }[];
    filters: Filters;
}) {
    function applyFilter(key: string, value: string) {
        router.get(
            adminRoutes.products.index().url,
            { ...filters, [key]: value, page: 1 },
            { preserveState: true, preserveScroll: true },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Products — Admin" />
            <div className="flex flex-col gap-6 p-6">
                <PageHeader
                    title="Products"
                    description={`${products.meta.total} total`}
                />

                <div className="flex flex-wrap gap-3">
                    <Input
                        placeholder="Search name or SKU…"
                        className="h-9 w-64"
                        defaultValue={filters.search ?? ''}
                        onChange={(e) => applyFilter('search', e.target.value)}
                    />
                    <Select
                        value={filters.category_id || '__all__'}
                        onValueChange={(v) =>
                            applyFilter('category_id', v === '__all__' ? '' : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-44">
                            <SelectValue placeholder="Category" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">
                                All categories
                            </SelectItem>
                            {categories.map((c) => (
                                <SelectItem key={c.id} value={String(c.id)}>
                                    {c.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.brand_id || '__all__'}
                        onValueChange={(v) =>
                            applyFilter('brand_id', v === '__all__' ? '' : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-40">
                            <SelectValue placeholder="Brand" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">All brands</SelectItem>
                            {brands.map((b) => (
                                <SelectItem key={b.id} value={String(b.id)}>
                                    {b.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.type || '__all__'}
                        onValueChange={(v) =>
                            applyFilter('type', v === '__all__' ? '' : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-36">
                            <SelectValue placeholder="Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">All types</SelectItem>
                            <SelectItem value="simple">Simple</SelectItem>
                            <SelectItem value="variable">Variable</SelectItem>
                            <SelectItem value="grouped">Grouped</SelectItem>
                            <SelectItem value="digital">Digital</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.in_stock || '__all__'}
                        onValueChange={(v) =>
                            applyFilter('in_stock', v === '__all__' ? '' : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-36">
                            <SelectValue placeholder="Stock" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">Any stock</SelectItem>
                            <SelectItem value="true">In stock</SelectItem>
                            <SelectItem value="false">Out of stock</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.is_active || '__all__'}
                        onValueChange={(v) =>
                            applyFilter('is_active', v === '__all__' ? '' : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-32">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">All</SelectItem>
                            <SelectItem value="true">Active</SelectItem>
                            <SelectItem value="false">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.is_featured || '__all__'}
                        onValueChange={(v) =>
                            applyFilter('is_featured', v === '__all__' ? '' : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-36">
                            <SelectValue placeholder="Featured" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">
                                All products
                            </SelectItem>
                            <SelectItem value="true">Featured</SelectItem>
                            <SelectItem value="false">Not featured</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.best_seller || '__all__'}
                        onValueChange={(v) =>
                            applyFilter('best_seller', v === '__all__' ? '' : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-40">
                            <SelectValue placeholder="Best sellers" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">
                                All products
                            </SelectItem>
                            <SelectItem value="true">
                                Best sellers (by rank)
                            </SelectItem>
                            <SelectItem value="false">
                                Not best sellers
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div className="rounded-lg border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Image</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>SKU</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead>Brand</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead className="text-right">
                                    Price
                                </TableHead>
                                <TableHead>Stock</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {products.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={10}
                                        className="py-8 text-center text-muted-foreground"
                                    >
                                        No products found
                                    </TableCell>
                                </TableRow>
                            )}
                            {products.data.map((product) => (
                                <TableRow key={product.id}>
                                    <TableCell>
                                        {product.primary_image?.public_url ? (
                                            <img
                                                src={
                                                    product.primary_image
                                                        .public_url
                                                }
                                                alt={product.name}
                                                className="h-10 w-10 rounded object-cover"
                                            />
                                        ) : (
                                            <div className="h-10 w-10 rounded bg-muted" />
                                        )}
                                    </TableCell>
                                    <TableCell className="max-w-[200px] truncate font-medium">
                                        {product.is_featured && (
                                            <Star
                                                className="mr-1 inline size-3.5 fill-amber-400 text-amber-500"
                                                aria-label="Featured"
                                            />
                                        )}
                                        {product.best_seller_rank !== null && (
                                            <Badge
                                                variant="outline"
                                                className="mr-1 border-amber-300 px-1.5 py-0 text-[10px] text-amber-700"
                                            >
                                                #{product.best_seller_rank}
                                            </Badge>
                                        )}
                                        {product.name}
                                    </TableCell>
                                    <TableCell className="font-mono text-sm text-muted-foreground">
                                        {product.sku}
                                    </TableCell>
                                    <TableCell className="text-sm text-muted-foreground">
                                        {product.category?.name ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-sm text-muted-foreground">
                                        {product.brand?.name ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        {typeBadge(product.type)}
                                    </TableCell>
                                    <TableCell className="text-right text-sm">
                                        {product.sale_price &&
                                        parseFloat(product.sale_price) <
                                            parseFloat(
                                                product.regular_price ?? '0',
                                            ) ? (
                                            <span>
                                                <span className="mr-1 text-muted-foreground line-through">
                                                    {product.regular_price}
                                                </span>
                                                <span className="text-red-600">
                                                    {product.sale_price}
                                                </span>
                                            </span>
                                        ) : (
                                            (product.regular_price ?? '—')
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {product.in_stock ? (
                                            <Badge className="border-green-200 bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400">
                                                In Stock
                                            </Badge>
                                        ) : (
                                            <Badge variant="destructive">
                                                Out
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {product.is_active ? (
                                            <Badge variant="secondary">
                                                Active
                                            </Badge>
                                        ) : (
                                            <Badge variant="outline">
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
                                                        product.id,
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
                </div>
                <DataTablePagination
                    meta={products.meta}
                    filters={filters}
                    routeUrl={adminRoutes.products.index().url}
                />
            </div>
        </AppLayout>
    );
}

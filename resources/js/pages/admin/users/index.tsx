import { Head, Link, router } from '@inertiajs/react';
import { Plus, Store } from 'lucide-react';
import { DataTablePagination } from '@/components/admin/data-table';
import { PageHeader } from '@/components/admin/page-header';
import { WooLoginBadge } from '@/components/admin/woo-login-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import adminRoutes from '@/routes/admin';
import type { BreadcrumbItem } from '@/types';
import type { AdminUser, Paginated } from '@/types/admin';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: adminRoutes.dashboard().url },
    { title: 'Users', href: adminRoutes.users.index().url },
];

interface Filters extends Record<string, string | number | undefined> {
    search?: string;
    approval_status?: string;
    is_admin?: string;
    woo?: string;
    per_page?: string;
}

function approvalBadge(status: string) {
    if (status === 'approved') {
return <Badge className="bg-green-100 text-green-800 border-green-200 dark:bg-green-900/20 dark:text-green-400">Approved</Badge>;
}

    if (status === 'rejected') {
return <Badge variant="destructive">Rejected</Badge>;
}

    return <Badge variant="secondary">Pending</Badge>;
}

export default function UsersIndex({ users, filters }: { users: Paginated<AdminUser>; filters: Filters }) {
    function applyFilter(key: string, value: string) {
        router.get(adminRoutes.users.index().url, { ...filters, [key]: value, page: 1 }, { preserveState: true, preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Users — Admin" />
            <div className="flex flex-col gap-6 p-6">
                <PageHeader
                    title="Users"
                    description={`${users.meta.total} total`}
                    action={
                        <Button asChild size="sm">
                            <Link href={adminRoutes.users.create().url}>
                                <Plus className="mr-1 h-4 w-4" /> Add User
                            </Link>
                        </Button>
                    }
                />

                {/* Filters */}
                <div className="flex flex-wrap gap-3">
                    <Input
                        placeholder="Search name, email, phone…"
                        className="h-9 w-64"
                        defaultValue={filters.search ?? ''}
                        onChange={(e) => applyFilter('search', e.target.value)}
                    />
                    <Select value={filters.approval_status || '__all__'} onValueChange={(v) => applyFilter('approval_status', v === '__all__' ? '' : v)}>
                        <SelectTrigger className="h-9 w-40">
                            <SelectValue placeholder="Approval status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">All statuses</SelectItem>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="approved">Approved</SelectItem>
                            <SelectItem value="rejected">Rejected</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={filters.is_admin || '__all__'} onValueChange={(v) => applyFilter('is_admin', v === '__all__' ? '' : v)}>
                        <SelectTrigger className="h-9 w-36">
                            <SelectValue placeholder="Role" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">All roles</SelectItem>
                            <SelectItem value="true">Admin</SelectItem>
                            <SelectItem value="false">User</SelectItem>
                        </SelectContent>
                    </Select>
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="woo"
                            checked={filters.woo === '1'}
                            onCheckedChange={(checked) => applyFilter('woo', checked === true ? '1' : '')}
                        />
                        <Label htmlFor="woo" className="flex items-center gap-1 font-normal">
                            <Store className="h-3.5 w-3.5 text-violet-600 dark:text-violet-400" />
                            Woo login only
                        </Label>
                    </div>
                </div>

                <div className="rounded-lg border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Email</TableHead>
                                <TableHead>Phone</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead className="text-right">Orders</TableHead>
                                <TableHead>Joined</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={8} className="text-center text-muted-foreground py-8">
                                        No users found
                                    </TableCell>
                                </TableRow>
                            )}
                            {users.data.map((user) => (
                                <TableRow key={user.id}>
                                    <TableCell className="font-medium">
                                        <div className="flex items-center gap-2">
                                            {user.name}
                                            {user.has_woo_login && <WooLoginBadge user={user} />}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">{user.email}</TableCell>
                                    <TableCell className="text-muted-foreground">{user.phone ?? '—'}</TableCell>
                                    <TableCell>{approvalBadge(user.approval_status)}</TableCell>
                                    <TableCell>
                                        {user.is_admin ? <Badge>Admin</Badge> : <Badge variant="outline">User</Badge>}
                                    </TableCell>
                                    <TableCell className="text-right">{user.orders_count ?? 0}</TableCell>
                                    <TableCell className="text-muted-foreground text-sm">
                                        {new Date(user.created_at).toLocaleDateString()}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center justify-end gap-2">
                                            <Button asChild variant="ghost" size="sm">
                                                <Link href={adminRoutes.users.show(user.id).url}>View</Link>
                                            </Button>
                                            <Button asChild variant="ghost" size="sm">
                                                <Link href={adminRoutes.users.edit(user.id).url}>Edit</Link>
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <DataTablePagination meta={users.meta} filters={filters} routeUrl={adminRoutes.users.index().url} />
            </div>
        </AppLayout>
    );
}

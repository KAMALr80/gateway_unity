import { Head, Link, useForm } from '@inertiajs/react';
import { PageHeader } from '@/components/admin/page-header';
import { WooLoginBadge } from '@/components/admin/woo-login-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import adminRoutes from '@/routes/admin';
import type { BreadcrumbItem } from '@/types';
import type { AdminUser, AdminOrder } from '@/types/admin';

function approvalBadge(status: string) {
    if (status === 'approved') {
return <Badge className="bg-green-100 text-green-800 border-green-200 dark:bg-green-900/20 dark:text-green-400">Approved</Badge>;
}

    if (status === 'rejected') {
return <Badge variant="destructive">Rejected</Badge>;
}

    return <Badge variant="secondary">Pending</Badge>;
}

function orderStatusBadge(status: string) {
    const map: Record<string, string> = {
        pending: 'bg-yellow-100 text-yellow-800 border-yellow-200',
        processing: 'bg-blue-100 text-blue-800 border-blue-200',
        completed: 'bg-green-100 text-green-800 border-green-200',
        cancelled: 'bg-red-100 text-red-800 border-red-200',
    };

    return <Badge className={map[status] ?? ''}>{status}</Badge>;
}

export default function UserShow({ user }: { user: AdminUser }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Admin', href: adminRoutes.dashboard().url },
        { title: 'Users', href: adminRoutes.users.index().url },
        { title: user.name, href: adminRoutes.users.show(user.id).url },
    ];

    const { data, setData, patch, processing } = useForm({
        approval_status: user.approval_status,
    });

    function changeStatus() {
        patch(adminRoutes.users.updateStatus(user.id).url);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${user.name} — Admin`} />
            <div className="flex flex-col gap-6 p-6">
                <PageHeader
                    title={user.name}
                    action={
                        <Button asChild variant="outline" size="sm">
                            <Link href={adminRoutes.users.edit(user.id).url}>Edit</Link>
                        </Button>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* User Info */}
                    <Card>
                        <CardHeader><CardTitle>User Information</CardTitle></CardHeader>
                        <CardContent className="space-y-3">
                            <Row label="Email" value={user.email} />
                            <Row label="Phone" value={user.phone ?? '—'} />
                            <Row label="Address" value={user.address ?? '—'} />
                            <Row label="ERP Contact ID" value={user.erp_contact_id ?? '—'} />
                            <Row label="Contact Code" value={user.erp_contact_code ?? '—'} />
                            <div className="flex items-center gap-2">
                                <span className="text-sm text-muted-foreground w-28 shrink-0">Login</span>
                                {user.has_woo_login ? (
                                    <span className="flex items-center gap-2 text-sm">
                                        <WooLoginBadge user={user} />
                                        {user.woo_username || user.erp_contact_code}
                                    </span>
                                ) : (
                                    <span className="text-sm">Own password</span>
                                )}
                            </div>
                            <Row label="Email Verified" value={user.email_verified_at ? new Date(user.email_verified_at).toLocaleDateString() : 'Not verified'} />
                            <Row label="Role" value={user.is_admin ? 'Admin' : 'User'} />
                            <Row label="Joined" value={new Date(user.created_at).toLocaleDateString()} />
                            <Separator />
                            <div className="flex items-center gap-3">
                                <span className="text-sm text-muted-foreground w-28">Approval</span>
                                {approvalBadge(user.approval_status)}
                            </div>
                            <div className="flex items-center gap-3">
                                <Select value={data.approval_status} onValueChange={(v) => setData('approval_status', v as 'pending' | 'approved' | 'rejected')}>
                                    <SelectTrigger className="h-8 w-36">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="pending">Pending</SelectItem>
                                        <SelectItem value="approved">Approved</SelectItem>
                                        <SelectItem value="rejected">Rejected</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Button size="sm" onClick={changeStatus} disabled={processing}>
                                    Change Status
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Addresses */}
                    <Card>
                        <CardHeader><CardTitle>Addresses ({user.addresses?.length ?? 0})</CardTitle></CardHeader>
                        <CardContent className="space-y-4">
                            {!user.addresses?.length && <p className="text-sm text-muted-foreground">No addresses saved</p>}
                            {user.addresses?.map((addr) => (
                                <div key={addr.id} className="text-sm space-y-0.5 p-3 rounded border bg-muted/30">
                                    <div className="flex items-center gap-2 font-medium">
                                        {addr.label ?? 'Address'}
                                        {addr.is_default && <Badge variant="secondary" className="text-xs">Default</Badge>}
                                    </div>
                                    <p>{addr.first_name} {addr.last_name}</p>
                                    {addr.company && <p>{addr.company}</p>}
                                    <p>{addr.address_1}{addr.address_2 ? `, ${addr.address_2}` : ''}</p>
                                    <p>{addr.city}{addr.state ? `, ${addr.state}` : ''} {addr.postcode}, {addr.country}</p>
                                    {addr.phone && <p>{addr.phone}</p>}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>

                {/* Recent Orders */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>Recent Orders ({user.orders_count ?? 0} total)</CardTitle>
                        <Button asChild variant="outline" size="sm">
                            <Link href={`${adminRoutes.orders.index().url}?search=${user.email}`}>All Orders</Link>
                        </Button>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Invoice</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Payment</TableHead>
                                    <TableHead className="text-right">Total</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {!user.orders?.length && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-muted-foreground py-6">No orders yet</TableCell>
                                    </TableRow>
                                )}
                                {(user.orders as AdminOrder[] | undefined)?.map((order) => (
                                    <TableRow key={order.id}>
                                        <TableCell className="font-mono text-sm">{order.invoice_no}</TableCell>
                                        <TableCell>{orderStatusBadge(order.status)}</TableCell>
                                        <TableCell><Badge variant="outline">{order.payment_status}</Badge></TableCell>
                                        <TableCell className="text-right">{order.currency} {parseFloat(order.total).toFixed(2)}</TableCell>
                                        <TableCell className="text-sm text-muted-foreground">{new Date(order.created_at).toLocaleDateString()}</TableCell>
                                        <TableCell>
                                            <Button asChild variant="ghost" size="sm">
                                                <Link href={adminRoutes.orders.show(order.id).url}>View</Link>
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-start gap-2">
            <span className="text-sm text-muted-foreground w-28 shrink-0">{label}</span>
            <span className="text-sm">{value}</span>
        </div>
    );
}

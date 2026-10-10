import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeftRight,
    CalendarDays,
    CheckCircle2,
    Layers3,
    LayoutTemplate,
    Pencil,
    Plus,
    Settings2,
    Trash2,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import type { HomepageConfig } from '@/types/admin';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: adminRoutes.dashboard().url },
    { title: 'Homepage Configs', href: adminRoutes.homepage.index().url },
];

export default function HomepageIndex({
    configs,
}: {
    configs: HomepageConfig[];
}) {
    const activeConfigs = configs.filter((config) => config.is_active).length;
    const sectionCount = configs.reduce(
        (total, config) => total + (config.sections_count ?? 0),
        0,
    );

    function deleteConfig(config: HomepageConfig) {
        if (
            !confirm(
                `Delete config "${config.label}"? This will also remove all its sections.`,
            )
        ) {
            return;
        }

        router.delete(adminRoutes.homepage.destroy(config.id).url);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Homepage Configs — Admin" />
            <div className="mx-auto flex w-full max-w-[1500px] flex-col gap-7 p-4 sm:p-6 lg:p-8">
                <div className="relative isolate overflow-hidden rounded-2xl border bg-gradient-to-br from-slate-950 via-slate-900 to-slate-800 px-6 py-8 text-white shadow-sm sm:px-8">
                    <div className="pointer-events-none absolute -top-28 -right-20 -z-10 h-80 w-80 rounded-full bg-orange-500/20 blur-3xl" />
                    <div className="pointer-events-none absolute -bottom-32 left-1/3 -z-10 h-72 w-72 rounded-full bg-blue-500/20 blur-3xl" />
                    <div className="flex flex-col justify-between gap-6 md:flex-row md:items-end">
                        <div>
                            <span className="mb-3 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-medium text-slate-200">
                                <LayoutTemplate className="h-3.5 w-3.5" />
                                Content workspace
                            </span>
                            <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                                Homepage management
                            </h1>
                            <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
                                Manage every storefront layout, section and
                                visual asset from a single publishing workspace.
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-3">
                            <Button
                                asChild
                                size="lg"
                                variant="outline"
                                className="border-white/30 bg-transparent text-white hover:bg-white/10 hover:text-white"
                            >
                                <Link href="/admin/homepage-transfer">
                                    <ArrowLeftRight className="mr-2 h-4 w-4" />
                                    Import / export
                                </Link>
                            </Button>
                            <Button
                                asChild
                                size="lg"
                                className="bg-white text-slate-950 shadow-lg hover:bg-slate-100"
                            >
                                <Link href={adminRoutes.homepage.create().url}>
                                    <Plus className="mr-2 h-4 w-4" />
                                    Create configuration
                                </Link>
                            </Button>
                        </div>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-2xl border bg-card p-5 shadow-sm">
                        <div className="flex items-center justify-between">
                            <p className="text-sm font-medium text-muted-foreground">
                                Configurations
                            </p>
                            <Settings2 className="h-5 w-5 text-blue-500" />
                        </div>
                        <p className="mt-3 text-3xl font-bold">
                            {configs.length}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Storefront workspaces
                        </p>
                    </div>
                    <div className="rounded-2xl border bg-card p-5 shadow-sm">
                        <div className="flex items-center justify-between">
                            <p className="text-sm font-medium text-muted-foreground">
                                Published
                            </p>
                            <CheckCircle2 className="h-5 w-5 text-emerald-500" />
                        </div>
                        <p className="mt-3 text-3xl font-bold">
                            {activeConfigs}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Active configurations
                        </p>
                    </div>
                    <div className="rounded-2xl border bg-card p-5 shadow-sm">
                        <div className="flex items-center justify-between">
                            <p className="text-sm font-medium text-muted-foreground">
                                Sections
                            </p>
                            <Layers3 className="h-5 w-5 text-orange-500" />
                        </div>
                        <p className="mt-3 text-3xl font-bold">
                            {sectionCount}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Managed homepage sections
                        </p>
                    </div>
                </div>

                <section>
                    <div className="mb-4">
                        <h2 className="text-lg font-semibold">
                            Storefront configurations
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Open a workspace to manage its sections and site
                            settings.
                        </p>
                    </div>
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Label</TableHead>
                                    <TableHead>Client Key</TableHead>
                                    <TableHead className="text-right">
                                        Sections
                                    </TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Created</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {configs.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="py-16 text-center text-muted-foreground"
                                        >
                                            <LayoutTemplate className="mx-auto mb-3 h-8 w-8 opacity-40" />
                                            <p className="font-medium text-foreground">
                                                No configurations yet
                                            </p>
                                            <p className="mt-1 text-sm">
                                                Create your first storefront
                                                workspace to begin.
                                            </p>
                                        </TableCell>
                                    </TableRow>
                                )}
                                {configs.map((config) => (
                                    <TableRow key={config.id} className="group">
                                        <TableCell>
                                            <div className="flex items-center gap-3">
                                                <span className="rounded-xl bg-blue-500/10 p-2.5 text-blue-600 dark:text-blue-400">
                                                    <LayoutTemplate className="h-4 w-4" />
                                                </span>
                                                <div>
                                                    <p className="font-semibold">
                                                        {config.label}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        Storefront homepage
                                                    </p>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <code className="rounded-md border bg-muted/60 px-2 py-1 text-xs font-medium">
                                                {config.client_key}
                                            </code>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {config.sections_count ?? 0}
                                        </TableCell>
                                        <TableCell>
                                            {config.is_active ? (
                                                <Badge className="border-green-200 bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400">
                                                    Active
                                                </Badge>
                                            ) : (
                                                <Badge variant="secondary">
                                                    Inactive
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            <span className="inline-flex items-center gap-1.5">
                                                <CalendarDays className="h-3.5 w-3.5" />
                                                {new Date(
                                                    config.created_at,
                                                ).toLocaleDateString()}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center justify-end gap-2">
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                    size="sm"
                                                >
                                                    <Link
                                                        href={
                                                            adminRoutes.homepage.edit(
                                                                config.id,
                                                            ).url
                                                        }
                                                    >
                                                        <Pencil className="h-4 w-4" />
                                                        <span className="ml-1">
                                                            Edit
                                                        </span>
                                                    </Link>
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        deleteConfig(config)
                                                    }
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
            </div>
        </AppLayout>
    );
}

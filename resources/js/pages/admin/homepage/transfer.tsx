import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Download,
    History,
    RotateCcw,
    Upload,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
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

type Problem = { code: string; path: string; message: string };
type SectionChange = {
    ref: string;
    title: string;
    items?: number | { added: number; removed: number; updated: number };
    fields?: string[];
};
type Summary = {
    replaces_homepage: boolean;
    creates_homepage: boolean;
    unchanged: boolean;
    order_changed: boolean;
    config: { field: string; change: string }[];
    sections: {
        added: SectionChange[];
        removed: SectionChange[];
        updated: SectionChange[];
    };
    counts: Record<string, number>;
};
type Transfer = {
    id: string;
    kind: 'import' | 'rollback';
    client_key: string;
    status: string;
    interrupted: boolean;
    package_id: string | null;
    base_fingerprint: string | null;
    summary: Summary | null;
    validation: {
        package: {
            export_id: string;
            exported_at: string | null;
            source_environment: string | null;
            includes: Record<string, number> | null;
        } | null;
        errors: Problem[];
        warnings: Problem[];
        references: Record<
            string,
            { referenced: number; resolved: number; missing_count: number }
        > | null;
    } | null;
    verification: {
        ok: boolean;
        checks: { name: string; ok: boolean; detail: string }[];
    } | null;
    backup: { available: boolean };
    can_roll_back: boolean;
    error: { category: string; message: string | null } | null;
    created_at: string | null;
};
type Config = {
    id: number;
    client_key: string;
    label: string;
    is_active: boolean;
};

const BASE = '/admin/homepage-transfer';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: adminRoutes.dashboard().url },
    { title: 'Homepage Configs', href: adminRoutes.homepage.index().url },
    { title: 'Import / export', href: BASE },
];

function csrfHeaders(): Record<string, string> {
    const token = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {};
}

async function send(url: string, body: FormData | Record<string, unknown>) {
    const isForm = body instanceof FormData;
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(isForm ? {} : { 'Content-Type': 'application/json' }),
            ...csrfHeaders(),
        },
        body: isForm ? body : JSON.stringify(body),
    });
    const json = await response.json().catch(() => ({}));

    return {
        status: response.status,
        data: json.data as Transfer | undefined,
        error: (json.error ?? null) as { code: string; message: string } | null,
        errors: json.errors,
    };
}

const statusTone: Record<string, string> = {
    succeeded: 'bg-emerald-100 text-emerald-800',
    validated: 'bg-blue-100 text-blue-800',
    in_progress: 'bg-amber-100 text-amber-800',
    failed: 'bg-red-100 text-red-800',
    invalid: 'bg-red-100 text-red-800',
    recovery_required: 'bg-red-200 text-red-900',
    rolled_back: 'bg-slate-200 text-slate-800',
    expired: 'bg-slate-100 text-slate-600',
};

function StatusBadge({
    status,
    interrupted,
}: {
    status: string;
    interrupted?: boolean;
}) {
    return (
        <Badge className={statusTone[status] ?? ''}>
            {interrupted ? 'interrupted' : status.replace('_', ' ')}
        </Badge>
    );
}

function ProblemList({
    title,
    items,
    tone,
}: {
    title: string;
    items: Problem[];
    tone: 'error' | 'warning';
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <div
            className={`rounded-lg border p-4 ${tone === 'error' ? 'border-red-200 bg-red-50' : 'border-amber-200 bg-amber-50'}`}
        >
            <p className="mb-2 flex items-center gap-2 text-sm font-semibold">
                {tone === 'error' ? (
                    <XCircle className="h-4 w-4 text-red-600" />
                ) : (
                    <AlertTriangle className="h-4 w-4 text-amber-600" />
                )}
                {title} ({items.length})
            </p>
            <ul className="space-y-1 text-sm">
                {items.slice(0, 50).map((item, index) => (
                    <li key={index}>
                        <span className="font-mono text-xs text-muted-foreground">
                            {item.path}
                        </span>{' '}
                        — {item.message}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Preview({ transfer }: { transfer: Transfer }) {
    const summary = transfer.summary;
    const validation = transfer.validation;

    return (
        <div className="space-y-4">
            {validation?.package && (
                <p className="text-sm text-muted-foreground">
                    Package{' '}
                    <span className="font-mono">
                        {validation.package.export_id}
                    </span>{' '}
                    for client <strong>{transfer.client_key}</strong>
                    {validation.package.source_environment
                        ? `, exported from ${validation.package.source_environment}`
                        : ''}
                    {validation.package.exported_at
                        ? ` at ${validation.package.exported_at}`
                        : ''}
                    .
                </p>
            )}
            <ProblemList
                title="Blocking problems"
                items={validation?.errors ?? []}
                tone="error"
            />
            <ProblemList
                title="Warnings"
                items={validation?.warnings ?? []}
                tone="warning"
            />
            {validation?.references && (
                <p className="text-sm">
                    References found here:{' '}
                    {Object.entries(validation.references)
                        .map(
                            ([kind, r]) =>
                                `${kind} ${r.resolved}/${r.referenced}`,
                        )
                        .join(' · ')}
                </p>
            )}
            {summary && (
                <div className="rounded-lg border p-4 text-sm">
                    <p className="mb-2 font-semibold">
                        {summary.unchanged
                            ? 'No changes: the homepage already matches this package.'
                            : summary.creates_homepage
                              ? `This creates the homepage for "${transfer.client_key}".`
                              : `This replaces the homepage of "${transfer.client_key}". Sections that are not in the package are removed.`}
                    </p>
                    {!summary.unchanged && (
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                Sections: {summary.counts.sections_added} added,{' '}
                                {summary.counts.sections_updated} updated,{' '}
                                {summary.counts.sections_removed} removed
                                {summary.order_changed ? ', order changes' : ''}
                            </li>
                            <li>
                                Items: {summary.counts.items_added} added,{' '}
                                {summary.counts.items_updated} updated,{' '}
                                {summary.counts.items_removed} removed
                            </li>
                            {summary.config.length > 0 && (
                                <li>
                                    Settings:{' '}
                                    {summary.config
                                        .map((c) => `${c.field} (${c.change})`)
                                        .join(', ')}
                                </li>
                            )}
                            {summary.sections.added.map((s) => (
                                <li key={`a${s.ref}`}>
                                    Add “{s.title}” ({s.ref})
                                </li>
                            ))}
                            {summary.sections.updated.map((s) => (
                                <li key={`u${s.ref}`}>
                                    Update “{s.title}” ({s.ref})
                                    {s.fields?.length
                                        ? `: ${s.fields.join(', ')}`
                                        : ''}
                                </li>
                            ))}
                            {summary.sections.removed.map((s) => (
                                <li key={`r${s.ref}`}>
                                    Remove “{s.title}” ({s.ref})
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}

export default function HomepageTransfer({
    configs,
    imports,
    limits,
}: {
    configs: Config[];
    imports: Transfer[];
    limits: { max_upload_mb: number };
}) {
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<{
        tone: 'ok' | 'error';
        text: string;
    } | null>(null);
    const [pending, setPending] = useState<Transfer | null>(null);
    const [acknowledged, setAcknowledged] = useState(false);

    async function validate() {
        if (!file) {
            return;
        }

        setBusy(true);
        setMessage(null);
        setPending(null);
        setAcknowledged(false);
        const body = new FormData();
        body.append('package', file);
        const result = await send(`${BASE}/validate`, body);
        setBusy(false);

        if (result.data) {
            setPending(result.data);

            if (result.data.status !== 'validated') {
                setMessage({
                    tone: 'error',
                    text: 'The package cannot be imported. See the problems below.',
                });
            }
        } else {
            setMessage({
                tone: 'error',
                text:
                    result.error?.message ??
                    'The package could not be validated.',
            });
        }
    }

    async function confirmImport() {
        if (!pending?.base_fingerprint) {
            return;
        }

        setBusy(true);
        const result = await send(`${BASE}/imports/${pending.id}/confirm`, {
            base_fingerprint: pending.base_fingerprint,
            confirm_replace: acknowledged,
        });
        setBusy(false);

        if (result.data?.status === 'succeeded') {
            setMessage({
                tone: 'ok',
                text: 'Imported and verified. The previous homepage is kept as a backup.',
            });
            setPending(null);
        } else {
            setMessage({
                tone: 'error',
                text:
                    result.error?.message ??
                    result.data?.error?.message ??
                    `The import ended as "${result.data?.status ?? 'failed'}".`,
            });
        }

        router.reload({ only: ['imports'] });
    }

    async function rollBack(transfer: Transfer, acknowledgeOverwrite = false) {
        if (
            !acknowledgeOverwrite &&
            !confirm(
                `Restore the homepage of "${transfer.client_key}" to how it was before this ${transfer.kind}?`,
            )
        ) {
            return;
        }

        setBusy(true);
        const result = await send(
            `${BASE}/imports/${transfer.id}/rollback`,
            acknowledgeOverwrite ? { acknowledge_overwrite: true } : {},
        );
        setBusy(false);

        if (result.error?.code === 'rollback.changed_since') {
            if (confirm(`${result.error.message}\n\nRoll back anyway?`)) {
                return rollBack(transfer, true);
            }
        } else if (result.data?.status === 'succeeded') {
            setMessage({ tone: 'ok', text: 'Rolled back and verified.' });
        } else {
            setMessage({
                tone: 'error',
                text:
                    result.error?.message ??
                    `The rollback ended as "${result.data?.status ?? 'failed'}".`,
            });
        }

        router.reload({ only: ['imports'] });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Homepage import / export — Admin" />
            <div className="mx-auto flex w-full max-w-[1200px] flex-col gap-6 p-4 sm:p-6 lg:p-8">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">
                        Homepage import / export
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Move a homepage between Gateways (for example local →
                        live). Only homepage content travels: no users, orders
                        or product records. Products, categories and brands are
                        matched by their ERP ids and must already exist here.
                    </p>
                </div>

                {message && (
                    <div
                        className={`rounded-lg border p-3 text-sm ${message.tone === 'ok' ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50'}`}
                    >
                        {message.text}
                    </div>
                )}

                <section className="rounded-xl border p-5">
                    <h2 className="mb-3 flex items-center gap-2 font-semibold">
                        <Download className="h-4 w-4" /> Export
                    </h2>
                    <div className="flex flex-wrap gap-2">
                        {configs.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No homepage configured yet.
                            </p>
                        )}
                        {configs.map((config) => (
                            <Button key={config.id} variant="outline" asChild>
                                <a href={`/admin/homepage/${config.id}/export`}>
                                    {config.label}{' '}
                                    <span className="ml-1 font-mono text-xs text-muted-foreground">
                                        {config.client_key}
                                    </span>
                                </a>
                            </Button>
                        ))}
                    </div>
                </section>

                <section className="rounded-xl border p-5">
                    <h2 className="mb-3 flex items-center gap-2 font-semibold">
                        <Upload className="h-4 w-4" /> Import
                    </h2>
                    <p className="mb-3 text-sm text-muted-foreground">
                        Step 1 validates and previews the package (up to{' '}
                        {limits.max_upload_mb} MB). Nothing changes until you
                        confirm in step 2.
                    </p>
                    <div className="flex flex-wrap items-center gap-3">
                        <input
                            type="file"
                            accept=".zip,application/zip"
                            onChange={(event) =>
                                setFile(event.target.files?.[0] ?? null)
                            }
                            className="text-sm"
                        />
                        <Button onClick={validate} disabled={!file || busy}>
                            Validate and preview
                        </Button>
                    </div>

                    {pending && (
                        <div className="mt-5 space-y-4">
                            <div className="flex items-center gap-2 text-sm">
                                Validation{' '}
                                <StatusBadge status={pending.status} />
                            </div>
                            <Preview transfer={pending} />
                            {pending.status === 'validated' &&
                                !pending.summary?.unchanged && (
                                    <div className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4">
                                        <label className="flex items-start gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={acknowledged}
                                                onChange={(event) =>
                                                    setAcknowledged(
                                                        event.target.checked,
                                                    )
                                                }
                                                className="mt-1"
                                            />
                                            <span>
                                                I understand this replaces the
                                                homepage of{' '}
                                                <strong>
                                                    {pending.client_key}
                                                </strong>{' '}
                                                as previewed. A backup is taken
                                                first and can be restored from
                                                the history below.
                                            </span>
                                        </label>
                                        <Button
                                            onClick={confirmImport}
                                            disabled={!acknowledged || busy}
                                        >
                                            Confirm import
                                        </Button>
                                    </div>
                                )}
                        </div>
                    )}
                </section>

                <section className="rounded-xl border p-5">
                    <h2 className="mb-3 flex items-center gap-2 font-semibold">
                        <History className="h-4 w-4" /> History and backups
                    </h2>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>When</TableHead>
                                <TableHead>What</TableHead>
                                <TableHead>Client</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Changes</TableHead>
                                <TableHead>Checks</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {imports.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="text-center text-sm text-muted-foreground"
                                    >
                                        Nothing imported yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {imports.map((transfer) => (
                                <TableRow key={transfer.id}>
                                    <TableCell className="text-xs whitespace-nowrap">
                                        {transfer.created_at
                                            ?.replace('T', ' ')
                                            .slice(0, 16)}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {transfer.kind}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {transfer.client_key}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={transfer.status}
                                            interrupted={transfer.interrupted}
                                        />
                                        {transfer.error?.message && (
                                            <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                                                {transfer.error.message}
                                            </p>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-xs">
                                        {transfer.summary
                                            ? `+${transfer.summary.counts.sections_added} ~${transfer.summary.counts.sections_updated} −${transfer.summary.counts.sections_removed} sections`
                                            : '—'}
                                    </TableCell>
                                    <TableCell>
                                        {transfer.verification ? (
                                            transfer.verification.ok ? (
                                                <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                                            ) : (
                                                <XCircle className="h-4 w-4 text-red-600" />
                                            )
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {transfer.can_roll_back && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    rollBack(transfer)
                                                }
                                                disabled={busy}
                                            >
                                                <RotateCcw className="mr-1 h-3.5 w-3.5" />{' '}
                                                Roll back
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </section>
            </div>
        </AppLayout>
    );
}

import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, Plus } from 'lucide-react';
import * as LeaveController from '@/actions/App/Http/Controllers/Leave/LeaveController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Employee, Leave, PaginatedLeaves } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Cuti', href: LeaveController.index.url() },
];

const TYPE_LABEL: Record<Leave['type'], string> = {
    annual: 'Cuti Tahunan',
    sick: 'Cuti Sakit',
    permit: 'Izin',
};

const STATUS_LABEL: Record<Leave['status'], string> = {
    pending: 'Menunggu',
    approved: 'Disetujui',
    rejected: 'Ditolak',
};

const STATUS_VARIANT: Record<
    Leave['status'],
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pending: 'outline',
    approved: 'default',
    rejected: 'destructive',
};

const STATUS_OPTIONS = [
    { value: '', label: 'Semua' },
    { value: 'pending', label: 'Menunggu' },
    { value: 'approved', label: 'Disetujui' },
    { value: 'rejected', label: 'Ditolak' },
];

type Filters = {
    status: string | null;
    type: string | null;
    employee_id: number | null;
};

function applyFilter(filters: Filters, patch: Partial<Filters>) {
    router.get(
        LeaveController.index.url(),
        { ...filters, ...patch },
        {
            preserveState: true,
            only: ['leaves', 'filters'],
        },
    );
}

function formatDate(dateStr: string) {
    return new Date(dateStr).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function LeavesIndex({
    leaves,
    employees,
    filters,
}: {
    leaves: PaginatedLeaves;
    employees: Pick<Employee, 'id' | 'name' | 'employee_number'>[];
    filters: Filters;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cuti" />

            <div className="space-y-6 p-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-2">
                        <CalendarDays className="size-5 text-muted-foreground" />
                        <h1 className="text-xl font-semibold tracking-tight">
                            Cuti
                        </h1>
                    </div>
                    <Button
                        render={<Link href={LeaveController.create.url()} />}
                    >
                        <Plus className="size-4" />
                        Tambah Pengajuan
                    </Button>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap gap-3">
                    {/* Status pills */}
                    <div className="flex gap-1.5">
                        {STATUS_OPTIONS.map((opt) => (
                            <button
                                key={opt.value}
                                onClick={() =>
                                    applyFilter(filters, {
                                        status: opt.value || null,
                                    })
                                }
                                className={`rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                                    (filters.status ?? '') === opt.value
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border bg-background text-muted-foreground hover:bg-muted'
                                }`}
                            >
                                {opt.label}
                            </button>
                        ))}
                    </div>

                    {/* Employee filter */}
                    <select
                        value={filters.employee_id ?? ''}
                        onChange={(e) =>
                            applyFilter(filters, {
                                employee_id: e.target.value
                                    ? Number(e.target.value)
                                    : null,
                            })
                        }
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <option value="">Semua Karyawan</option>
                        {employees.map((e) => (
                            <option key={e.id} value={e.id}>
                                {e.name}
                            </option>
                        ))}
                    </select>

                    {/* Type filter */}
                    <select
                        value={filters.type ?? ''}
                        onChange={(e) =>
                            applyFilter(filters, {
                                type: e.target.value || null,
                            })
                        }
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <option value="">Semua Jenis</option>
                        <option value="annual">Cuti Tahunan</option>
                        <option value="sick">Cuti Sakit</option>
                        <option value="permit">Izin</option>
                    </select>
                </div>

                {/* Table */}
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Karyawan
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Jenis
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Tanggal
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Hari
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {leaves.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-10 text-center text-muted-foreground"
                                    >
                                        Tidak ada data cuti.
                                    </td>
                                </tr>
                            ) : (
                                leaves.data.map((leave) => (
                                    <tr
                                        key={leave.id}
                                        className="border-b last:border-0 hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3">
                                            <Link
                                                href={LeaveController.show.url(
                                                    leave,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {leave.employee?.name ?? '—'}
                                            </Link>
                                            <p className="font-mono text-xs text-muted-foreground">
                                                {
                                                    leave.employee
                                                        ?.employee_number
                                                }
                                            </p>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {TYPE_LABEL[leave.type]}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {formatDate(leave.start_date)}
                                            {leave.start_date !==
                                                leave.end_date && (
                                                <>
                                                    {' '}
                                                    &ndash;{' '}
                                                    {formatDate(leave.end_date)}
                                                </>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {leave.days}h
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant={
                                                    STATUS_VARIANT[leave.status]
                                                }
                                            >
                                                {STATUS_LABEL[leave.status]}
                                            </Badge>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {leaves.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <span>
                            Menampilkan {leaves.from}–{leaves.to} dari{' '}
                            {leaves.total} data
                        </span>
                        <div className="flex gap-1">
                            {leaves.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url && router.visit(link.url)
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

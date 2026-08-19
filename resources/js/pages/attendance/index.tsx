import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ClipboardList } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index as attendanceIndex } from '@/routes/attendance';
import { show } from '@/routes/employees';
import type { AttendanceRecord, BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Kehadiran', href: attendanceIndex.url() },
];

const STATUS_LABEL: Record<AttendanceRecord['status'], string> = {
    present: 'Hadir',
    late: 'Terlambat',
    absent: 'Tidak Hadir',
    leave: 'Cuti',
    holiday: 'Libur',
};

const STATUS_VARIANT: Record<
    AttendanceRecord['status'],
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    present: 'default',
    late: 'secondary',
    absent: 'destructive',
    leave: 'outline',
    holiday: 'outline',
};

const STATUS_FILTER_OPTIONS: { value: string; label: string }[] = [
    { value: '', label: 'Semua' },
    { value: 'present', label: 'Hadir' },
    { value: 'late', label: 'Terlambat' },
    { value: 'absent', label: 'Tidak Hadir' },
    { value: 'leave', label: 'Cuti' },
];

function formatTime(time: string | null) {
    if (!time) return '—';
    return time.slice(0, 5);
}

function formatDuration(record: AttendanceRecord) {
    if (!record.check_in || !record.check_out) return '—';
    const [ih, im] = record.check_in.split(':').map(Number);
    const [oh, om] = record.check_out.split(':').map(Number);
    let totalMinutes = oh * 60 + om - (ih * 60 + im);
    if (totalMinutes <= 0) return '—';

    // Subtract break duration when present.
    if (record.break_start && record.break_end) {
        const [bh, bm] = record.break_start.split(':').map(Number);
        const [eh, em] = record.break_end.split(':').map(Number);
        const breakMinutes = eh * 60 + em - (bh * 60 + bm);
        totalMinutes -= Math.max(0, breakMinutes);
    }

    const h = Math.floor(totalMinutes / 60);
    const m = totalMinutes % 60;
    return h > 0 ? `${h}j ${m}m` : `${m}m`;
}

function shiftDate(current: string, days: number): string {
    const d = new Date(current);
    d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
}

function formatDisplayDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

type Filters = {
    date: string;
    department: string | null;
    status: string | null;
};

function applyFilter(filters: Filters, patch: Partial<Filters>) {
    router.get(
        attendanceIndex.url(),
        { ...filters, ...patch },
        { preserveState: true, only: ['records', 'filters'] },
    );
}

export default function AttendanceIndex({
    records,
    departments,
    filters,
    isWorkingDay,
}: {
    records: AttendanceRecord[];
    departments: string[];
    filters: Filters;
    isWorkingDay: boolean;
}) {
    const isToday = filters.date === new Date().toISOString().slice(0, 10);
    const { features } = usePage().props;
    const breakEnabled = features?.break !== false;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Kehadiran" />

            <div className="space-y-6 p-6">
                {/* Page Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-2">
                        <ClipboardList className="size-5 text-muted-foreground" />
                        <div>
                            <h1 className="text-xl font-semibold tracking-tight">
                                Kehadiran
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {formatDisplayDate(filters.date)}
                            </p>
                        </div>
                    </div>

                    {/* Date Navigator */}
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                applyFilter(filters, {
                                    date: shiftDate(filters.date, -1),
                                })
                            }
                        >
                            <ChevronLeft className="size-4" />
                        </Button>
                        <input
                            type="date"
                            value={filters.date}
                            onChange={(e) =>
                                applyFilter(filters, { date: e.target.value })
                            }
                            className="h-9 rounded-md border border-input bg-background px-3 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                        />
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={isToday}
                            onClick={() =>
                                applyFilter(filters, {
                                    date: shiftDate(filters.date, 1),
                                })
                            }
                        >
                            <ChevronRight className="size-4" />
                        </Button>
                        {!isToday && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    applyFilter(filters, {
                                        date: new Date()
                                            .toISOString()
                                            .slice(0, 10),
                                    })
                                }
                            >
                                Hari Ini
                            </Button>
                        )}
                    </div>
                </div>

                {!isWorkingDay && (
                    <div className="rounded-lg border border-dashed bg-muted/40 px-4 py-2 text-sm text-muted-foreground">
                        Hari libur — ketidakhadiran tidak dihitung.
                    </div>
                )}

                {/* Filters */}
                <div className="flex flex-wrap gap-3">
                    {/* Department */}
                    {departments.length > 0 && (
                        <select
                            value={filters.department ?? ''}
                            onChange={(e) =>
                                applyFilter(filters, {
                                    department: e.target.value || null,
                                })
                            }
                            className="h-9 rounded-md border border-input bg-background px-3 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                        >
                            <option value="">Semua Departemen</option>
                            {departments.map((d) => (
                                <option key={d} value={d}>
                                    {d}
                                </option>
                            ))}
                        </select>
                    )}

                    {/* Status pills */}
                    <div className="flex gap-1.5">
                        {STATUS_FILTER_OPTIONS.map((opt) => (
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
                </div>

                {/* Table */}
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    No.
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Karyawan
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Departemen
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Masuk
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Pulang
                                </th>
                                {breakEnabled && (
                                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                        Istirahat
                                    </th>
                                )}
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Durasi
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {records.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={breakEnabled ? 8 : 7}
                                        className="px-4 py-10 text-center text-muted-foreground"
                                    >
                                        Tidak ada data untuk ditampilkan.
                                    </td>
                                </tr>
                            ) : (
                                records.map((record) => (
                                    <tr
                                        key={record.employee_id}
                                        className="border-b last:border-0 hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                                            {record.employee_number}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link
                                                href={show.url(
                                                    record.employee_id,
                                                )}
                                                className="font-medium hover:underline"
                                                prefetch
                                            >
                                                {record.name}
                                            </Link>
                                            {record.position && (
                                                <p className="text-xs text-muted-foreground">
                                                    {record.position}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {record.department ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-xs">
                                            {formatTime(record.check_in)}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-xs">
                                            {formatTime(record.check_out)}
                                        </td>
                                        {breakEnabled && (
                                            <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                                                {record.break_start &&
                                                record.break_end
                                                    ? `${formatTime(record.break_start)} – ${formatTime(record.break_end)}`
                                                    : '—'}
                                            </td>
                                        )}
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {formatDuration(record)}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant={
                                                    STATUS_VARIANT[
                                                        record.status
                                                    ]
                                                }
                                            >
                                                {STATUS_LABEL[record.status]}
                                            </Badge>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}

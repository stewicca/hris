import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarDays, ClipboardList } from 'lucide-react';
import { index as leavesIndex } from '@/actions/App/Http/Controllers/Leave/LeaveController';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { index as attendanceIndex } from '@/routes/attendance';
import type { AttendanceSummary, BreadcrumbItem } from '@/types';

type LeaveStats = {
    pending: number;
    on_leave_today: number;
    approved_this_month: number;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard.url(),
    },
];

function formatDisplayDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

export default function Dashboard({
    summary,
    date,
    leaveStats,
}: {
    summary: AttendanceSummary;
    date: string;
    leaveStats: LeaveStats;
}) {
    const { features } = usePage().props;
    const leaveEnabled = features?.leave !== false;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <div className="space-y-6 p-6">
                {/* Attendance Summary */}
                <div>
                    <div className="mb-3 flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <ClipboardList className="size-4 text-muted-foreground" />
                            <div>
                                <h2 className="text-sm font-semibold">
                                    Kehadiran Hari Ini
                                    {!summary.is_working_day && (
                                        <span className="ml-2 rounded-full border px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                                            Hari Libur
                                        </span>
                                    )}
                                </h2>
                                <p className="text-xs text-muted-foreground">
                                    {formatDisplayDate(date)}
                                </p>
                            </div>
                        </div>
                        <Link
                            href={attendanceIndex.url()}
                            className="text-xs text-muted-foreground hover:text-foreground hover:underline"
                        >
                            Lihat semua
                        </Link>
                    </div>

                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div className="rounded-lg border bg-card p-4">
                            <p className="text-2xl font-bold text-foreground">
                                {summary.total}
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Total Aktif
                            </p>
                        </div>
                        <div className="rounded-lg border bg-card p-4">
                            <p className="text-2xl font-bold text-foreground">
                                {summary.present}
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Hadir
                            </p>
                        </div>
                        <div className="rounded-lg border bg-card p-4">
                            <p className="text-2xl font-bold text-amber-600 dark:text-amber-400">
                                {summary.late}
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Terlambat
                            </p>
                        </div>
                        <div className="rounded-lg border bg-card p-4">
                            <p className="text-2xl font-bold text-muted-foreground">
                                {summary.absent}
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Tidak Hadir
                            </p>
                        </div>
                    </div>
                </div>

                {/* Leave Summary */}
                {leaveEnabled && (
                    <div>
                        <div className="mb-3 flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <CalendarDays className="size-4 text-muted-foreground" />
                                <h2 className="text-sm font-semibold">Cuti</h2>
                            </div>
                            <Link
                                href={leavesIndex.url()}
                                className="text-xs text-muted-foreground hover:text-foreground hover:underline"
                            >
                                Lihat semua
                            </Link>
                        </div>

                        <div className="grid grid-cols-3 gap-3">
                            <Link
                                href={leavesIndex.url({ status: 'pending' })}
                                className="rounded-lg border bg-card p-4 transition-colors hover:bg-accent"
                            >
                                <p className="text-2xl font-bold text-amber-600 dark:text-amber-400">
                                    {leaveStats.pending}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Menunggu
                                </p>
                            </Link>
                            <div className="rounded-lg border bg-card p-4">
                                <p className="text-2xl font-bold text-foreground">
                                    {leaveStats.on_leave_today}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Sedang Cuti
                                </p>
                            </div>
                            <div className="rounded-lg border bg-card p-4">
                                <p className="text-2xl font-bold text-foreground">
                                    {leaveStats.approved_this_month}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Disetujui Bulan Ini
                                </p>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

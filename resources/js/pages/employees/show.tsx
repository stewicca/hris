import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Banknote,
    Building2,
    Calendar,
    Camera,
    Check,
    Download,
    Mail,
    Pencil,
    Phone,
    Plus,
    Printer,
    RefreshCw,
    ScanFace,
    Trash2,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import {
    store as enrollFace,
    destroy as unenrollFace,
} from '@/actions/App/Http/Controllers/Api/EnrollmentController';
import { attendanceExport } from '@/actions/App/Http/Controllers/Employees/EmployeeController';
import { store as storeSalary } from '@/actions/App/Http/Controllers/Salary/SalaryController';
import { FaceCaptureDialog } from '@/components/face-capture-dialog';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { edit, index, show } from '@/routes/employees';
import {
    destroy as destroySalary,
    markPaid,
    print as printSalary,
} from '@/routes/salaries';
import type {
    Attendance,
    AttendanceSummary,
    BreadcrumbItem,
    Employee,
    LeaveSummary,
    MonthlyAttendance,
    PaginatedAttendances,
    Salary,
    SalaryComponent,
} from '@/types';

const STATUS_LABEL: Record<Attendance['status'], string> = {
    present: 'Hadir',
    late: 'Terlambat',
    absent: 'Absen',
};

const STATUS_VARIANT: Record<
    Attendance['status'],
    'default' | 'secondary' | 'destructive'
> = {
    present: 'default',
    late: 'secondary',
    absent: 'destructive',
};

function initials(name: string) {
    return name
        .split(' ')
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase();
}

function formatTime(time: string | null) {
    if (!time) return '—';
    return time.slice(0, 5);
}

function formatDuration(checkIn: string | null, checkOut: string | null) {
    if (!checkIn || !checkOut) return '—';
    const [ih, im] = checkIn.split(':').map(Number);
    const [oh, om] = checkOut.split(':').map(Number);
    const totalMinutes = oh * 60 + om - (ih * 60 + im);
    if (totalMinutes <= 0) return '—';
    const h = Math.floor(totalMinutes / 60);
    const m = totalMinutes % 60;
    return h > 0 ? `${h}j ${m}m` : `${m}m`;
}

function formatDate(dateStr: string) {
    return new Date(dateStr).toLocaleDateString('id-ID', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function formatMonth(year: number, month: number) {
    return new Date(year, month - 1).toLocaleDateString('id-ID', {
        month: 'long',
        year: 'numeric',
    });
}

function formatRupiah(amount: number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(amount);
}

const DEFAULT_COMPONENTS: SalaryComponent[] = [
    { label: 'Gaji Pokok', amount: 5000000, type: 'income' },
    { label: 'Tunjangan Transport', amount: 500000, type: 'income' },
    { label: 'Tunjangan Makan', amount: 750000, type: 'income' },
    { label: 'BPJS Kesehatan', amount: 130000, type: 'deduction' },
    { label: 'BPJS Ketenagakerjaan', amount: 200000, type: 'deduction' },
    { label: 'PPh 21', amount: 250000, type: 'deduction' },
];

function SalarySection({
    employee,
    salaries,
}: {
    employee: Employee;
    salaries: Salary[];
}) {
    const [showForm, setShowForm] = useState(false);
    const form = useForm<{ period: string; components: SalaryComponent[] }>({
        period: new Date().toISOString().slice(0, 7),
        components: DEFAULT_COMPONENTS,
    });

    const net = form.data.components.reduce(
        (sum, c) => sum + (c.type === 'income' ? c.amount : -c.amount),
        0,
    );

    const updateComponent = (i: number, patch: Partial<SalaryComponent>) => {
        form.setData(
            'components',
            form.data.components.map((c, idx) =>
                idx === i ? { ...c, ...patch } : c,
            ),
        );
    };

    const addComponent = () => {
        form.setData('components', [
            ...form.data.components,
            { label: '', amount: 0, type: 'income' },
        ]);
    };

    const removeComponent = (i: number) => {
        form.setData(
            'components',
            form.data.components.filter((_, idx) => idx !== i),
        );
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(storeSalary.url(employee), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setShowForm(false);
            },
        });
    };

    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between pb-3">
                <CardTitle className="flex items-center gap-2 text-base font-semibold">
                    <Banknote className="size-4" />
                    Penggajian
                </CardTitle>
                <Button
                    size="sm"
                    variant={showForm ? 'ghost' : 'outline'}
                    onClick={() => setShowForm((v) => !v)}
                >
                    {showForm ? (
                        'Batal'
                    ) : (
                        <>
                            <Plus className="size-4" />
                            Buat Slip Gaji
                        </>
                    )}
                </Button>
            </CardHeader>
            <CardContent className="space-y-4">
                {showForm && (
                    <form
                        onSubmit={submit}
                        className="space-y-3 rounded-lg border bg-muted/30 p-4"
                    >
                        <div className="flex flex-wrap items-end gap-3">
                            <div>
                                <label className="text-xs font-medium text-muted-foreground">
                                    Periode
                                </label>
                                <Input
                                    type="month"
                                    value={form.data.period}
                                    onChange={(e) =>
                                        form.setData('period', e.target.value)
                                    }
                                    className="mt-1 w-44"
                                />
                            </div>
                            <p className="ml-auto text-sm text-muted-foreground">
                                Gaji bersih:{' '}
                                <span className="font-semibold text-foreground">
                                    {formatRupiah(net)}
                                </span>
                            </p>
                        </div>
                        {form.errors.period && (
                            <p className="text-xs text-destructive">
                                {form.errors.period}
                            </p>
                        )}

                        <div className="space-y-2">
                            {form.data.components.map((c, i) => (
                                <div
                                    key={i}
                                    className="flex items-center gap-2"
                                >
                                    <Input
                                        value={c.label}
                                        placeholder="Komponen"
                                        onChange={(e) =>
                                            updateComponent(i, {
                                                label: e.target.value,
                                            })
                                        }
                                        className="flex-1"
                                    />
                                    <Input
                                        type="number"
                                        min={0}
                                        value={c.amount}
                                        onChange={(e) =>
                                            updateComponent(i, {
                                                amount: Number(e.target.value),
                                            })
                                        }
                                        className="w-36"
                                    />
                                    <select
                                        value={c.type}
                                        onChange={(e) =>
                                            updateComponent(i, {
                                                type: e.target
                                                    .value as SalaryComponent['type'],
                                            })
                                        }
                                        className="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                                    >
                                        <option value="income">
                                            Pendapatan
                                        </option>
                                        <option value="deduction">
                                            Potongan
                                        </option>
                                    </select>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        onClick={() => removeComponent(i)}
                                    >
                                        <Trash2 className="size-4 text-destructive" />
                                    </Button>
                                </div>
                            ))}
                        </div>

                        <div className="flex items-center justify-between">
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={addComponent}
                            >
                                <Plus className="size-4" />
                                Tambah komponen
                            </Button>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={form.processing}
                            >
                                Simpan
                            </Button>
                        </div>
                    </form>
                )}

                {salaries.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        Belum ada slip gaji.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                        Periode
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium text-muted-foreground">
                                        Bersih
                                    </th>
                                    <th className="px-4 py-3 text-center font-medium text-muted-foreground">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium text-muted-foreground">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {salaries.map((s) => (
                                    <tr
                                        key={s.id}
                                        className="border-b last:border-0 hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3 font-medium capitalize">
                                            {s.period_label}
                                        </td>
                                        <td className="px-4 py-3 text-right font-mono text-xs">
                                            {formatRupiah(s.net)}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <Badge
                                                variant={
                                                    s.status === 'paid'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {s.status === 'paid'
                                                    ? 'Dibayar'
                                                    : 'Proses'}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        window.open(
                                                            printSalary.url(s),
                                                            '_blank',
                                                            'noopener',
                                                        )
                                                    }
                                                >
                                                    <Printer className="size-4" />
                                                </Button>
                                                {s.status === 'pending' && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.post(
                                                                markPaid.url(s),
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Check className="size-4" />
                                                        Bayar
                                                    </Button>
                                                )}
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    onClick={() => {
                                                        if (
                                                            confirm(
                                                                'Hapus slip gaji ini?',
                                                            )
                                                        ) {
                                                            router.delete(
                                                                destroySalary.url(
                                                                    s,
                                                                ),
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }
                                                    }}
                                                >
                                                    <Trash2 className="size-4 text-destructive" />
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function MonthlyRecapTable({ recap }: { recap: MonthlyAttendance[] }) {
    if (recap.length === 0) {
        return (
            <p className="py-6 text-center text-sm text-muted-foreground">
                Belum ada data kehadiran.
            </p>
        );
    }

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b bg-muted/50">
                        <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                            Bulan
                        </th>
                        <th className="px-4 py-3 text-center font-medium text-muted-foreground">
                            Hadir
                        </th>
                        <th className="px-4 py-3 text-center font-medium text-muted-foreground">
                            Terlambat
                        </th>
                        <th className="px-4 py-3 text-center font-medium text-muted-foreground">
                            Absen
                        </th>
                        <th className="px-4 py-3 text-center font-medium text-muted-foreground">
                            Total
                        </th>
                        <th className="px-4 py-3 text-center font-medium text-muted-foreground">
                            Kehadiran
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {recap.map((row) => {
                        const rate =
                            row.total > 0
                                ? Math.round(
                                      ((row.present + row.late) / row.total) *
                                          100,
                                  )
                                : 0;
                        return (
                            <tr
                                key={`${row.year}-${row.month}`}
                                className="border-b last:border-0 hover:bg-muted/30"
                            >
                                <td className="px-4 py-3 font-medium capitalize">
                                    {formatMonth(row.year, row.month)}
                                </td>
                                <td className="px-4 py-3 text-center">
                                    {row.present}
                                </td>
                                <td className="px-4 py-3 text-center text-amber-600 dark:text-amber-400">
                                    {row.late}
                                </td>
                                <td className="px-4 py-3 text-center text-destructive">
                                    {row.absent}
                                </td>
                                <td className="px-4 py-3 text-center text-muted-foreground">
                                    {row.total}
                                </td>
                                <td className="px-4 py-3 text-center">
                                    <span
                                        className={
                                            rate >= 80
                                                ? 'font-medium text-primary'
                                                : rate >= 60
                                                  ? 'font-medium text-amber-600 dark:text-amber-400'
                                                  : 'font-medium text-destructive'
                                        }
                                    >
                                        {rate}%
                                    </span>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function AttendanceSkeleton() {
    return (
        <div className="space-y-2">
            {Array.from({ length: 5 }).map((_, i) => (
                <Skeleton key={i} className="h-12 w-full rounded-lg" />
            ))}
        </div>
    );
}

function AttendanceTable({
    attendances,
}: {
    attendances: PaginatedAttendances;
}) {
    return (
        <div className="space-y-4">
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b bg-muted/50">
                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                Tanggal
                            </th>
                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                Masuk
                            </th>
                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                Pulang
                            </th>
                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                Durasi
                            </th>
                            <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                Status
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {attendances.data.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={5}
                                    className="px-4 py-10 text-center text-muted-foreground"
                                >
                                    Belum ada data kehadiran.
                                </td>
                            </tr>
                        ) : (
                            attendances.data.map((record) => (
                                <tr
                                    key={record.id}
                                    className="border-b last:border-0 hover:bg-muted/30"
                                >
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {formatDate(record.date)}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs">
                                        {formatTime(record.check_in)}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs">
                                        {formatTime(record.check_out)}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {formatDuration(
                                            record.check_in,
                                            record.check_out,
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Badge
                                            variant={
                                                STATUS_VARIANT[record.status]
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

            {attendances.last_page > 1 && (
                <div className="flex items-center justify-between text-sm text-muted-foreground">
                    <span>
                        Menampilkan {attendances.from}–{attendances.to} dari{' '}
                        {attendances.total} data
                    </span>
                    <div className="flex gap-1">
                        {attendances.links.map((link, i) => (
                            <Button
                                key={i}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() =>
                                    link.url && router.visit(link.url)
                                }
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

function FaceEnrollmentSection({
    employee,
    onChanged,
}: {
    employee: Employee;
    onChanged: () => void;
}) {
    const [open, setOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const enrolled = !!employee.face_enrolled_at;

    const submitEnrollment = async (blob: Blob) => {
        setSubmitting(true);
        setError(null);
        const form = new FormData();
        form.append('image', blob, 'enrollment.jpg');

        const res = await fetch(enrollFace.url(employee), {
            method: 'POST',
            body: form,
            credentials: 'include',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
        });
        if (!res.ok) {
            const data = await res.json().catch(() => null);
            const msg = data?.errors
                ? Object.values(data.errors).flat().join(' ')
                : (data?.message ?? 'Gagal mendaftarkan wajah.');
            throw new Error(msg);
        }
        setOpen(false);
        onChanged();
    };

    const removeEnrollment = async () => {
        if (
            !confirm(
                'Hapus pendaftaran wajah? Karyawan tidak bisa absen dengan wajah sampai didaftarkan ulang.',
            )
        )
            return;
        setSubmitting(true);
        try {
            const res = await fetch(unenrollFace.url(employee), {
                method: 'DELETE',
                credentials: 'include',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
            });
            if (!res.ok) throw new Error('Gagal menghapus pendaftaran.');
            onChanged();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Gagal menghapus.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between pb-3">
                <CardTitle className="flex items-center gap-2 text-base font-semibold">
                    <ScanFace className="size-4" />
                    Verifikasi Wajah
                </CardTitle>
                {enrolled ? (
                    <Badge
                        variant="secondary"
                        className="bg-primary/10 text-primary"
                    >
                        Terdaftar
                    </Badge>
                ) : (
                    <Badge variant="outline">Belum Terdaftar</Badge>
                )}
            </CardHeader>
            <CardContent className="space-y-3">
                <p className="text-sm text-muted-foreground">
                    {enrolled
                        ? `Wajah terdaftar pada ${new Date(employee.face_enrolled_at!).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })}. Karyawan dapat absen menggunakan verifikasi wajah.`
                        : 'Wajah belum terdaftar. Karyawan tidak dapat melakukan absensi wajah sampai didaftarkan.'}
                </p>

                {error && <p className="text-xs text-destructive">{error}</p>}

                {enrolled ? (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setOpen(true)}
                            disabled={submitting}
                        >
                            <RefreshCw className="size-4" />
                            Daftarkan Ulang
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={removeEnrollment}
                            disabled={submitting}
                        >
                            <Trash2 className="size-4" />
                            Hapus Pendaftaran
                        </Button>
                    </div>
                ) : (
                    <Button
                        size="sm"
                        onClick={() => setOpen(true)}
                        disabled={submitting}
                    >
                        <Camera className="size-4" />
                        Daftarkan Wajah
                    </Button>
                )}

                <FaceCaptureDialog
                    open={open}
                    onClose={() => {
                        setOpen(false);
                        setSubmitting(false);
                    }}
                    onCapture={submitEnrollment}
                    mode="challenges"
                    challengeCount={3}
                    submitLabel="Menyimpan pendaftaran..."
                />
            </CardContent>
        </Card>
    );
}

export default function EmployeesShow({
    employee,
    leaveSummary,
    salaries,
    attendanceSummary,
    monthlyRecap,
    attendances,
}: {
    employee: Employee;
    leaveSummary: LeaveSummary;
    salaries: Salary[];
    attendanceSummary: AttendanceSummary;
    monthlyRecap: MonthlyAttendance[];
    attendances?: PaginatedAttendances;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Karyawan', href: index.url() },
        { title: employee.name, href: show.url(employee) },
    ];

    const { features } = usePage().props;
    const leaveEnabled = features?.leave !== false;
    const payrollEnabled = features?.payroll !== false;

    const attendanceRate =
        attendanceSummary.total > 0
            ? Math.round(
                  ((attendanceSummary.present + attendanceSummary.late) /
                      attendanceSummary.total) *
                      100,
              )
            : 0;

    const currentMonth = new Date().toLocaleDateString('id-ID', {
        month: 'long',
        year: 'numeric',
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="space-y-6 p-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex items-center gap-4">
                        <Avatar className="size-16">
                            <AvatarFallback className="text-lg font-semibold">
                                {initials(employee.name)}
                            </AvatarFallback>
                        </Avatar>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-xl font-semibold tracking-tight">
                                    {employee.name}
                                </h1>
                                {employee.user?.is_admin && (
                                    <Badge
                                        variant="secondary"
                                        className="bg-primary/10 text-primary"
                                    >
                                        Admin
                                    </Badge>
                                )}
                                <Badge
                                    variant={
                                        employee.status === 'active'
                                            ? 'default'
                                            : 'secondary'
                                    }
                                >
                                    {employee.status === 'active'
                                        ? 'Aktif'
                                        : 'Nonaktif'}
                                </Badge>
                            </div>
                            <p className="font-mono text-xs text-muted-foreground">
                                {employee.employee_number}
                            </p>
                            {(employee.position || employee.department) && (
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    {[
                                        employee.position?.name,
                                        employee.department?.name,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                            )}
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            render={
                                <a
                                    href={attendanceExport.url(employee)}
                                    download
                                />
                            }
                        >
                            <Download className="size-4" />
                            Export CSV
                        </Button>
                        <Button
                            variant="outline"
                            render={<Link href={edit.url(employee)} prefetch />}
                        >
                            <Pencil className="size-4" />
                            Edit
                        </Button>
                    </div>
                </div>

                {/* Info Cards Row */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="flex items-center gap-3 rounded-lg border bg-card px-4 py-3">
                        <Mail className="size-4 shrink-0 text-muted-foreground" />
                        <div className="min-w-0">
                            <p className="truncate text-sm">{employee.email}</p>
                            <p className="text-xs text-muted-foreground">
                                Email
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3 rounded-lg border bg-card px-4 py-3">
                        <Phone className="size-4 shrink-0 text-muted-foreground" />
                        <div className="min-w-0">
                            <p className="truncate text-sm">
                                {employee.phone ?? '—'}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Telepon
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3 rounded-lg border bg-card px-4 py-3">
                        <Banknote className="size-4 shrink-0 text-muted-foreground" />
                        <div className="min-w-0">
                            <p className="truncate text-sm">
                                {employee.bank_account_number ?? '—'}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                No. Rekening
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3 rounded-lg border bg-card px-4 py-3">
                        <Building2 className="size-4 shrink-0 text-muted-foreground" />
                        <div className="min-w-0">
                            <p className="truncate text-sm">
                                {employee.department?.name ?? '—'}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Departemen
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3 rounded-lg border bg-card px-4 py-3">
                        <Calendar className="size-4 shrink-0 text-muted-foreground" />
                        <div className="min-w-0">
                            <p className="truncate text-sm">
                                {employee.hire_date
                                    ? new Date(
                                          employee.hire_date,
                                      ).toLocaleDateString('id-ID', {
                                          day: 'numeric',
                                          month: 'short',
                                          year: 'numeric',
                                      })
                                    : '—'}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Bergabung
                            </p>
                        </div>
                    </div>
                </div>

                {/* Attendance Summary */}
                <FaceEnrollmentSection
                    employee={employee}
                    onChanged={() => router.reload({ only: ['employee'] })}
                />

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-base font-semibold">
                            <UserRound className="size-4" />
                            Kehadiran Bulan Ini
                            <span className="text-sm font-normal text-muted-foreground">
                                ({currentMonth})
                            </span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <div className="rounded-lg bg-muted/50 px-4 py-3 text-center">
                                <p className="text-2xl font-bold text-foreground">
                                    {attendanceSummary.present}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Hadir
                                </p>
                            </div>
                            <div className="rounded-lg bg-muted/50 px-4 py-3 text-center">
                                <p className="text-2xl font-bold text-amber-600 dark:text-amber-400">
                                    {attendanceSummary.late}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Terlambat
                                </p>
                            </div>
                            <div className="rounded-lg bg-muted/50 px-4 py-3 text-center">
                                <p className="text-2xl font-bold text-destructive">
                                    {attendanceSummary.absent}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Absen
                                </p>
                            </div>
                            <div className="rounded-lg bg-muted/50 px-4 py-3 text-center">
                                <p className="text-2xl font-bold text-primary">
                                    {attendanceRate}%
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Tingkat Kehadiran
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Leave Balance */}
                {leaveEnabled && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <Calendar className="size-4" />
                                Saldo Cuti Tahunan
                                <span className="text-sm font-normal text-muted-foreground">
                                    ({new Date().getFullYear()})
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                                <div className="rounded-lg bg-muted/50 px-4 py-3 text-center">
                                    <p className="text-2xl font-bold text-foreground">
                                        {leaveSummary.quota}
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Kuota
                                    </p>
                                </div>
                                <div className="rounded-lg bg-muted/50 px-4 py-3 text-center">
                                    <p className="text-2xl font-bold text-foreground">
                                        {leaveSummary.used}
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Terpakai
                                    </p>
                                </div>
                                <div className="rounded-lg bg-muted/50 px-4 py-3 text-center">
                                    <p className="text-2xl font-bold text-amber-600 dark:text-amber-400">
                                        {leaveSummary.pending}
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Menunggu
                                    </p>
                                </div>
                                <div className="rounded-lg bg-muted/50 px-4 py-3 text-center">
                                    <p className="text-2xl font-bold text-primary">
                                        {leaveSummary.remaining}
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Sisa
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Salary */}
                {payrollEnabled && (
                    <SalarySection employee={employee} salaries={salaries} />
                )}

                {/* Monthly Recap */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base font-semibold">
                            Rekap Bulanan
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <MonthlyRecapTable recap={monthlyRecap} />
                    </CardContent>
                </Card>

                {/* Attendance History */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base font-semibold">
                            Riwayat Kehadiran
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {attendances === undefined ? (
                            <AttendanceSkeleton />
                        ) : (
                            <AttendanceTable attendances={attendances} />
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

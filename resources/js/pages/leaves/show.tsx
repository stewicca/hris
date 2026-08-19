import { Head, useForm } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import * as LeaveController from '@/actions/App/Http/Controllers/Leave/LeaveController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Leave } from '@/types';

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

function formatDate(dateStr: string) {
    return new Date(dateStr).toLocaleDateString('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

function DetailRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid grid-cols-3 gap-2 border-b py-3 last:border-0">
            <p className="text-sm text-muted-foreground">{label}</p>
            <div className="col-span-2 text-sm">{children}</div>
        </div>
    );
}

export default function LeavesShow({ leave }: { leave: Leave }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Cuti', href: LeaveController.index.url() },
        {
            title: leave.employee?.name ?? 'Detail',
            href: LeaveController.show.url(leave),
        },
    ];

    const approveForm = useForm({});
    const rejectForm = useForm({ rejection_reason: '' });
    const cancelForm = useForm({});

    function handleApprove() {
        if (!confirm('Setujui pengajuan cuti ini?')) return;
        approveForm.post(LeaveController.approve.url(leave));
    }

    function handleReject(e: React.FormEvent) {
        e.preventDefault();
        rejectForm.post(LeaveController.reject.url(leave));
    }

    function handleCancel() {
        if (!confirm('Batalkan pengajuan cuti ini?')) return;
        cancelForm.delete(LeaveController.destroy.url(leave));
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Detail Cuti" />

            <div className="p-6">
                <div className="mx-auto max-w-xl space-y-6">
                    {/* Header */}
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <CalendarDays className="size-5 text-muted-foreground" />
                            <h1 className="text-xl font-semibold tracking-tight">
                                Detail Cuti
                            </h1>
                        </div>
                        <Badge variant={STATUS_VARIANT[leave.status]}>
                            {STATUS_LABEL[leave.status]}
                        </Badge>
                    </div>

                    {/* Detail Card */}
                    <div className="rounded-lg border bg-card px-6 py-2">
                        <DetailRow label="Karyawan">
                            <span className="font-medium">
                                {leave.employee?.name}
                            </span>
                            <span className="ml-2 font-mono text-xs text-muted-foreground">
                                {leave.employee?.employee_number}
                            </span>
                        </DetailRow>
                        <DetailRow label="Jenis Cuti">
                            {TYPE_LABEL[leave.type]}
                        </DetailRow>
                        <DetailRow label="Tanggal Mulai">
                            {formatDate(leave.start_date)}
                        </DetailRow>
                        <DetailRow label="Tanggal Selesai">
                            {formatDate(leave.end_date)}
                        </DetailRow>
                        <DetailRow label="Jumlah Hari">
                            {leave.days} hari
                        </DetailRow>
                        <DetailRow label="Alasan">{leave.reason}</DetailRow>
                        {leave.rejection_reason && (
                            <DetailRow label="Alasan Penolakan">
                                <span className="text-destructive">
                                    {leave.rejection_reason}
                                </span>
                            </DetailRow>
                        )}
                        {leave.approved_at && (
                            <DetailRow label="Diproses Pada">
                                {new Date(leave.approved_at).toLocaleDateString(
                                    'id-ID',
                                    {
                                        day: 'numeric',
                                        month: 'long',
                                        year: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    },
                                )}
                            </DetailRow>
                        )}
                    </div>

                    {/* Actions */}
                    {leave.status === 'pending' && (
                        <div className="space-y-4">
                            {/* Approve */}
                            <Button className="w-full" onClick={handleApprove}>
                                Setujui
                            </Button>

                            {/* Reject */}
                            <form
                                onSubmit={handleReject}
                                className="space-y-3 rounded-lg border bg-card p-4"
                            >
                                <p className="text-sm font-medium">
                                    Tolak Pengajuan
                                </p>
                                <div className="grid gap-2">
                                    <Label htmlFor="rejection_reason">
                                        Alasan Penolakan
                                    </Label>
                                    <textarea
                                        id="rejection_reason"
                                        value={rejectForm.data.rejection_reason}
                                        onChange={(e) =>
                                            rejectForm.setData(
                                                'rejection_reason',
                                                e.target.value,
                                            )
                                        }
                                        rows={2}
                                        maxLength={500}
                                        placeholder="Masukkan alasan penolakan..."
                                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    />
                                    <InputError
                                        message={
                                            rejectForm.errors.rejection_reason
                                        }
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={rejectForm.processing}
                                    className="w-full"
                                >
                                    Tolak
                                </Button>
                            </form>

                            {/* Cancel */}
                            <Button
                                variant="outline"
                                className="w-full"
                                onClick={handleCancel}
                            >
                                Batalkan Pengajuan
                            </Button>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

import { router, usePage } from '@inertiajs/react';
import {
    ToggleLeft,
    CheckCircle2,
    MoonStar,
    Coffee,
    Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    index as settingsIndex,
    update,
} from '@/actions/App/Http/Controllers/FeatureSettingController';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pengaturan Fitur', href: settingsIndex.url() },
];

interface PageProps {
    leaveEnabled: boolean;
    breakEnabled: boolean;
    shiftEnabled: boolean;
    payrollEnabled: boolean;
}

interface ToggleCardProps {
    icon: React.ReactNode;
    title: string;
    description: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}

function ToggleCard({
    icon,
    title,
    description,
    checked,
    onChange,
}: ToggleCardProps) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-base font-semibold">
                    {icon}
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <label className="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm hover:bg-accent">
                    <input
                        type="checkbox"
                        className="size-4 accent-primary"
                        checked={checked}
                        onChange={(e) => onChange(e.target.checked)}
                    />
                    Aktifkan
                </label>
                <p className="text-xs text-muted-foreground">{description}</p>
            </CardContent>
        </Card>
    );
}

export default function FeatureSettingsIndex({
    leaveEnabled,
    breakEnabled,
    shiftEnabled,
    payrollEnabled,
}: PageProps) {
    const flash = usePage().props.flash;
    const [toast, setToast] = useState<string | null>(null);

    useEffect(() => {
        if (flash?.success) {
            setToast(flash.success);
            const t = setTimeout(() => setToast(null), 3500);
            return () => clearTimeout(t);
        }
    }, [flash?.success]);

    const [leaveOn, setLeaveOn] = useState(leaveEnabled);
    const [breakOn, setBreakOn] = useState(breakEnabled);
    const [shiftOn, setShiftOn] = useState(shiftEnabled);
    const [payrollOn, setPayrollOn] = useState(payrollEnabled);
    const [saving, setSaving] = useState(false);

    const submit = () => {
        router.put(
            update.url(),
            {
                leave_enabled: leaveOn,
                attendance_break_enabled: breakOn,
                attendance_shift_enabled: shiftOn,
                payroll_enabled: payrollOn,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="space-y-6 p-6">
                {/* Toast */}
                {toast && (
                    <div className="fixed top-4 right-4 z-50 flex items-center gap-2 rounded-lg border border-green-500/30 bg-green-500/10 px-4 py-3 text-sm font-medium text-green-700 shadow-lg dark:text-green-400">
                        <CheckCircle2 className="size-4" />
                        {toast}
                    </div>
                )}

                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        Pengaturan Fitur
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Aktifkan atau nonaktifkan modul aplikasi. Fitur yang
                        dinonaktifkan akan menyembunyikan seluruh menu, halaman,
                        dan field yang terkait serta menonaktifkan aksesnya.
                    </p>
                </div>

                <ToggleCard
                    icon={<ToggleLeft className="size-4" />}
                    title="Fitur Cuti"
                    description="Mencakup pengajuan cuti tahunan, cuti sakit, dan izin. Saat dinonaktifkan, menu Cuti pada sidebar, widget ringkasan cuti, dan field kuota cuti pada data karyawan akan disembunyikan. Pengajuan yang sudah ada tetap tersimpan dan dipertahankan sebagai alasan ketidakhadiran."
                    checked={leaveOn}
                    onChange={setLeaveOn}
                />

                <ToggleCard
                    icon={<Wallet className="size-4" />}
                    title="Fitur Penggajian"
                    description="Mencakup pembuatan slip gaji, riwayat penggajian, dan cetak slip gaji. Saat dinonaktifkan, bagian Penggajian pada detail karyawan, menu Slip Gaji pada portal karyawan, dan akses route penggajian disembunyikan. Data slip gaji yang sudah ada tetap tersimpan."
                    checked={payrollOn}
                    onChange={setPayrollOn}
                />

                <ToggleCard
                    icon={<MoonStar className="size-4" />}
                    title="Mode Shift"
                    description="Aktifkan penugasan shift per karyawan. Saat aktif, admin dapat menentukan shift (jam masuk, pulang, terlambat, dan istirahat) untuk masing-masing karyawan. Saat nonaktif, semua karyawan menggunakan jam kerja global."
                    checked={shiftOn}
                    onChange={setShiftOn}
                />

                <ToggleCard
                    icon={<Coffee className="size-4" />}
                    title="Fitur Istirahat"
                    description="Aktifkan pencatatan waktu istirahat. Saat aktif, karyawan dapat mencatat mulai dan selesai istirahat selama jam kerja. Istirahat bersifat opsional — karyawan dapat melewatinya. Durasi istirahat akan dikurangkan dari total jam kerja."
                    checked={breakOn}
                    onChange={setBreakOn}
                />

                <Button onClick={submit} disabled={saving}>
                    Simpan Pengaturan Fitur
                </Button>
            </div>
        </AppLayout>
    );
}

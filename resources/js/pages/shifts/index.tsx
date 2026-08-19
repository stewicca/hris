import { router, usePage } from '@inertiajs/react';
import {
    Clock,
    Coffee,
    Plus,
    Pencil,
    Trash2,
    CheckCircle2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    destroy as destroyShift,
    index as shiftIndex,
    store as storeShift,
    update as updateShift,
} from '@/actions/App/Http/Controllers/Shifts/ShiftController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Shift } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Shift', href: shiftIndex.url() },
];

type ShiftForm = {
    name: string;
    check_in: string;
    check_out: string;
    late_threshold: string;
    grace_minutes: number;
    break_enabled: boolean;
    break_start: string;
    break_end: string;
    is_active: boolean;
};

const emptyForm: ShiftForm = {
    name: '',
    check_in: '08:00',
    check_out: '17:00',
    late_threshold: '08:05',
    grace_minutes: 0,
    break_enabled: false,
    break_start: '12:00',
    break_end: '13:00',
    is_active: true,
};

function toForm(shift: Shift): ShiftForm {
    return {
        name: shift.name,
        check_in: shift.check_in.slice(0, 5),
        check_out: shift.check_out.slice(0, 5),
        late_threshold: shift.late_threshold.slice(0, 5),
        grace_minutes: shift.grace_minutes,
        break_enabled: shift.break_enabled,
        break_start: shift.break_start?.slice(0, 5) ?? '12:00',
        break_end: shift.break_end?.slice(0, 5) ?? '13:00',
        is_active: shift.is_active,
    };
}

function fmt(time: string | null): string {
    return time ? time.slice(0, 5) : '—';
}

export default function ShiftsIndex({ shifts }: { shifts: Shift[] }) {
    const flash = usePage().props.flash;
    const [toast, setToast] = useState<string | null>(null);

    useEffect(() => {
        if (flash?.success) {
            setToast(flash.success);
            const t = setTimeout(() => setToast(null), 3500);
            return () => clearTimeout(t);
        }
    }, [flash?.success]);

    const [form, setForm] = useState<ShiftForm>(emptyForm);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const resetForm = () => {
        setForm(emptyForm);
        setEditingId(null);
        setErrors({});
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        setSaving(true);
        setErrors({});

        const payload = {
            ...form,
            break_start: form.break_enabled ? form.break_start : null,
            break_end: form.break_enabled ? form.break_end : null,
        };

        const opts = {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: (errs: Record<string, string>) => setErrors(errs),
            onSuccess: () => resetForm(),
        };

        if (editingId) {
            router.put(updateShift.url(editingId), payload, opts);
        } else {
            router.post(storeShift.url(), payload, opts);
        }
    };

    const edit = (shift: Shift) => {
        setForm(toForm(shift));
        setEditingId(shift.id);
        setErrors({});
    };

    const remove = (shift: Shift) => {
        if (confirm(`Hapus shift "${shift.name}"?`)) {
            router.delete(destroyShift.url(shift.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="space-y-6 p-6">
                {toast && (
                    <div className="fixed top-4 right-4 z-50 flex items-center gap-2 rounded-lg border border-green-500/30 bg-green-500/10 px-4 py-3 text-sm font-medium text-green-700 shadow-lg dark:text-green-400">
                        <CheckCircle2 className="size-4" />
                        {toast}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                        {flash.error}
                    </div>
                )}

                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        Shift
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Kelola definisi shift kerja. Setiap shift memiliki jam
                        masuk, pulang, batas terlambat, dan jendela istirahat
                        sendiri.
                    </p>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Form */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <Clock className="size-4" />
                                {editingId ? 'Edit Shift' : 'Tambah Shift'}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="space-y-4">
                                <div className="space-y-1.5">
                                    <label className="text-sm font-medium">
                                        Nama Shift
                                    </label>
                                    <Input
                                        value={form.name}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                name: e.target.value,
                                            })
                                        }
                                        placeholder="Mis. Pagi, Siang, Malam"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid grid-cols-3 gap-3">
                                    <div className="space-y-1.5">
                                        <label className="text-sm font-medium">
                                            Masuk
                                        </label>
                                        <Input
                                            type="time"
                                            value={form.check_in}
                                            onChange={(e) =>
                                                setForm({
                                                    ...form,
                                                    check_in: e.target.value,
                                                })
                                            }
                                        />
                                        <InputError message={errors.check_in} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <label className="text-sm font-medium">
                                            Pulang
                                        </label>
                                        <Input
                                            type="time"
                                            value={form.check_out}
                                            onChange={(e) =>
                                                setForm({
                                                    ...form,
                                                    check_out: e.target.value,
                                                })
                                            }
                                        />
                                        <InputError
                                            message={errors.check_out}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <label className="text-sm font-medium">
                                            Terlambat
                                        </label>
                                        <Input
                                            type="time"
                                            value={form.late_threshold}
                                            onChange={(e) =>
                                                setForm({
                                                    ...form,
                                                    late_threshold:
                                                        e.target.value,
                                                })
                                            }
                                        />
                                        <InputError
                                            message={errors.late_threshold}
                                        />
                                    </div>
                                </div>

                                <div className="space-y-1.5">
                                    <label className="text-sm font-medium">
                                        Toleransi Terlambat (menit)
                                    </label>
                                    <Input
                                        type="number"
                                        min={0}
                                        max={480}
                                        value={form.grace_minutes}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                grace_minutes: Number(
                                                    e.target.value,
                                                ),
                                            })
                                        }
                                    />
                                    <InputError
                                        message={errors.grace_minutes}
                                    />
                                </div>

                                <label className="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm hover:bg-accent">
                                    <input
                                        type="checkbox"
                                        className="size-4 accent-primary"
                                        checked={form.break_enabled}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                break_enabled: e.target.checked,
                                            })
                                        }
                                    />
                                    <Coffee className="size-4 text-muted-foreground" />
                                    Aktifkan jendela istirahat untuk shift ini
                                </label>

                                {form.break_enabled && (
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="space-y-1.5">
                                            <label className="text-sm font-medium">
                                                Istirahat Mulai
                                            </label>
                                            <Input
                                                type="time"
                                                value={form.break_start}
                                                onChange={(e) =>
                                                    setForm({
                                                        ...form,
                                                        break_start:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={errors.break_start}
                                            />
                                        </div>
                                        <div className="space-y-1.5">
                                            <label className="text-sm font-medium">
                                                Istirahat Selesai
                                            </label>
                                            <Input
                                                type="time"
                                                value={form.break_end}
                                                onChange={(e) =>
                                                    setForm({
                                                        ...form,
                                                        break_end:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={errors.break_end}
                                            />
                                        </div>
                                    </div>
                                )}

                                <label className="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm hover:bg-accent">
                                    <input
                                        type="checkbox"
                                        className="size-4 accent-primary"
                                        checked={form.is_active}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                is_active: e.target.checked,
                                            })
                                        }
                                    />
                                    Aktif (tersedia untuk dipilih)
                                </label>

                                <div className="flex gap-2">
                                    <Button type="submit" disabled={saving}>
                                        <Plus className="size-4" />
                                        {editingId ? 'Simpan' : 'Tambah'}
                                    </Button>
                                    {editingId && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={resetForm}
                                        >
                                            Batal
                                        </Button>
                                    )}
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    {/* List */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                Daftar Shift
                                <span className="text-sm font-normal text-muted-foreground">
                                    ({shifts.length})
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {shifts.length === 0 ? (
                                <p className="py-8 text-center text-sm text-muted-foreground">
                                    Belum ada shift. Tambahkan shift pertama
                                    Anda.
                                </p>
                            ) : (
                                <ul className="space-y-2">
                                    {shifts.map((shift) => (
                                        <li
                                            key={shift.id}
                                            className="rounded-lg border p-3"
                                        >
                                            <div className="flex items-start justify-between">
                                                <div className="space-y-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-medium">
                                                            {shift.name}
                                                        </span>
                                                        {!shift.is_active && (
                                                            <span className="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                                                                Nonaktif
                                                            </span>
                                                        )}
                                                    </div>
                                                    <p className="text-xs text-muted-foreground">
                                                        Masuk{' '}
                                                        {fmt(shift.check_in)} ·
                                                        Pulang{' '}
                                                        {fmt(shift.check_out)} ·
                                                        Terlambat{' '}
                                                        {fmt(
                                                            shift.late_threshold,
                                                        )}
                                                        {shift.grace_minutes > 0
                                                            ? ` · Toleransi ${shift.grace_minutes}m`
                                                            : ''}
                                                    </p>
                                                    {shift.break_enabled && (
                                                        <p className="text-xs text-muted-foreground">
                                                            <Coffee className="mr-1 inline size-3" />
                                                            Istirahat{' '}
                                                            {fmt(
                                                                shift.break_start,
                                                            )}{' '}
                                                            –{' '}
                                                            {fmt(
                                                                shift.break_end,
                                                            )}
                                                        </p>
                                                    )}
                                                </div>
                                                <div className="flex gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-7"
                                                        onClick={() =>
                                                            edit(shift)
                                                        }
                                                    >
                                                        <Pencil className="size-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-7 text-muted-foreground hover:text-destructive"
                                                        onClick={() =>
                                                            remove(shift)
                                                        }
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                </div>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

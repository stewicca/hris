import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    destroy as destroyAttendance,
    store as storeAttendance,
} from '@/actions/App/Http/Controllers/Attendance/AttendanceController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AttendanceRecord } from '@/types';

/**
 * The statuses an admin picks from. 'late' is missing on purpose: it follows
 * from the check-in time, so choosing it would let a record contradict its own
 * clock.
 */
const STATUS_OPTIONS: { value: FormState['status']; label: string }[] = [
    { value: 'present', label: 'Hadir' },
    { value: 'sick', label: 'Sakit' },
    { value: 'permit', label: 'Izin' },
    { value: 'absent', label: 'Tidak Hadir' },
];

type FormState = {
    status: 'present' | 'sick' | 'permit' | 'absent';
    check_in: string;
    check_out: string;
    break_start: string;
    break_end: string;
    notes: string;
};

function hhmm(time: string | null): string {
    return time ? time.slice(0, 5) : '';
}

/**
 * Seed the form from whatever the day already holds. A record the employee
 * clocked opens on its own times, so adding a forgotten check-out never
 * disturbs the check-in that is already there.
 */
function toForm(record: AttendanceRecord): FormState {
    const stored =
        record.status === 'sick' ||
        record.status === 'permit' ||
        record.status === 'absent'
            ? record.status
            : 'present';

    return {
        status: stored,
        check_in: hhmm(record.check_in),
        check_out: hhmm(record.check_out),
        break_start: hhmm(record.break_start),
        break_end: hhmm(record.break_end),
        notes: record.notes ?? '',
    };
}

/**
 * Mount this with a key tied to the row being edited: each row gets a fresh
 * dialog seeded from its own record, so there is no state to re-sync when the
 * admin closes one row and opens another.
 */
export function ManualAttendanceDialog({
    record,
    date,
    breakEnabled,
    onClose,
}: {
    record: AttendanceRecord;
    date: string;
    breakEnabled: boolean;
    onClose: () => void;
}) {
    const [form, setForm] = useState<FormState>(() => toForm(record));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const attending = form.status === 'present';
    const excused = form.status === 'sick' || form.status === 'permit';

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        setErrors({});

        const payload = {
            employee_id: record.employee_id,
            date,
            status: form.status,
            notes: form.notes || null,
            // Times describe worked hours only; a day nobody worked carries none.
            ...(attending
                ? {
                      check_in: form.check_in || null,
                      check_out: form.check_out || null,
                      break_start: breakEnabled ? form.break_start || null : null,
                      break_end: breakEnabled ? form.break_end || null : null,
                  }
                : {}),
        };

        router.post(storeAttendance.url(), payload, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: (errs: Record<string, string>) => setErrors(errs),
            onSuccess: () => onClose(),
        });
    };

    const remove = () => {
        if (!record.attendance_id) {
            return;
        }

        router.delete(destroyAttendance.url(record.attendance_id), {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Absenkan {record.name}</DialogTitle>
                    <DialogDescription>
                        {new Date(date).toLocaleDateString('id-ID', {
                            weekday: 'long',
                            day: 'numeric',
                            month: 'long',
                            year: 'numeric',
                        })}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label>Status</Label>
                        <div className="grid grid-cols-2 gap-2">
                            {STATUS_OPTIONS.map((opt) => (
                                <button
                                    key={opt.value}
                                    type="button"
                                    onClick={() =>
                                        setForm({ ...form, status: opt.value })
                                    }
                                    className={`rounded-lg border px-3 py-2 text-sm font-medium transition-colors ${
                                        form.status === opt.value
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-border bg-background text-muted-foreground hover:bg-muted'
                                    }`}
                                >
                                    {opt.label}
                                </button>
                            ))}
                        </div>
                        <InputError message={errors.status} />
                    </div>

                    {attending ? (
                        <div className="space-y-3">
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="check_in">Jam Masuk</Label>
                                    <Input
                                        id="check_in"
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
                                    <Label htmlFor="check_out">Jam Pulang</Label>
                                    <Input
                                        id="check_out"
                                        type="time"
                                        value={form.check_out}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                check_out: e.target.value,
                                            })
                                        }
                                    />
                                    <InputError message={errors.check_out} />
                                </div>
                            </div>

                            {breakEnabled && (
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="break_start">
                                            Istirahat Mulai
                                        </Label>
                                        <Input
                                            id="break_start"
                                            type="time"
                                            value={form.break_start}
                                            onChange={(e) =>
                                                setForm({
                                                    ...form,
                                                    break_start: e.target.value,
                                                })
                                            }
                                        />
                                        <InputError
                                            message={errors.break_start}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="break_end">
                                            Istirahat Selesai
                                        </Label>
                                        <Input
                                            id="break_end"
                                            type="time"
                                            value={form.break_end}
                                            onChange={(e) =>
                                                setForm({
                                                    ...form,
                                                    break_end: e.target.value,
                                                })
                                            }
                                        />
                                        <InputError message={errors.break_end} />
                                    </div>
                                </div>
                            )}

                            <p className="text-xs text-muted-foreground">
                                Terlambat atau tidak ditentukan otomatis dari jam
                                masuk dan jadwal yang berlaku.
                            </p>
                        </div>
                    ) : (
                        <p className="rounded-lg border border-dashed bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                            Hari ini dicatat tanpa jam kerja. Jam yang sudah
                            tersimpan akan dihapus.
                        </p>
                    )}

                    <div className="space-y-1.5">
                        <Label htmlFor="notes">
                            Keterangan{' '}
                            {excused ? (
                                <span className="text-destructive">*</span>
                            ) : (
                                <span className="text-xs font-normal text-muted-foreground">
                                    (opsional)
                                </span>
                            )}
                        </Label>
                        <Input
                            id="notes"
                            value={form.notes}
                            maxLength={255}
                            placeholder={
                                excused
                                    ? 'Contoh: sakit demam, ada surat dokter'
                                    : 'Contoh: lupa absen, dikonfirmasi via WhatsApp'
                            }
                            onChange={(e) =>
                                setForm({ ...form, notes: e.target.value })
                            }
                        />
                        <InputError message={errors.notes} />
                    </div>

                    <InputError message={errors.date ?? errors.employee_id} />

                    <DialogFooter>
                        {record.can_delete && (
                            <Button
                                type="button"
                                variant="ghost"
                                disabled={saving}
                                onClick={remove}
                                className="text-destructive sm:mr-auto"
                            >
                                <Trash2 className="size-4" />
                                Hapus
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            disabled={saving}
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button type="submit" disabled={saving}>
                            {saving ? 'Menyimpan...' : 'Simpan'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

import { Form, router } from '@inertiajs/react';
import { CalendarCog, CalendarOff, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    destroyHoliday,
    index as workCalendarIndex,
    storeHoliday,
    update,
} from '@/actions/App/Http/Controllers/WorkCalendarController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Kalender Kerja', href: workCalendarIndex.url() },
];

const DAYS: { value: number; label: string }[] = [
    { value: 1, label: 'Senin' },
    { value: 2, label: 'Selasa' },
    { value: 3, label: 'Rabu' },
    { value: 4, label: 'Kamis' },
    { value: 5, label: 'Jumat' },
    { value: 6, label: 'Sabtu' },
    { value: 7, label: 'Minggu' },
];

interface Holiday {
    id: number;
    date: string;
    name: string;
}

function formatDate(date: string): string {
    return new Date(date).toLocaleDateString('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

export default function WorkCalendarIndex({
    workingDays,
    holidays,
}: {
    workingDays: number[];
    holidays: Holiday[];
}) {
    const [selected, setSelected] = useState<number[]>(workingDays);
    const [saving, setSaving] = useState(false);

    const toggle = (day: number) => {
        setSelected((prev) =>
            prev.includes(day) ? prev.filter((d) => d !== day) : [...prev, day],
        );
    };

    const saveWorkingDays = () => {
        router.put(
            update.url(),
            { working_days: selected },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
            },
        );
    };

    const removeHoliday = (id: number) => {
        if (confirm('Hapus hari libur ini?')) {
            router.delete(destroyHoliday.url(id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="space-y-6 p-6">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        Kalender Kerja
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Tentukan hari kerja dan hari libur. Karyawan yang tidak
                        absen pada hari kerja akan otomatis ditandai tidak
                        hadir.
                    </p>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <CalendarCog className="size-4" />
                                Hari Kerja
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-1">
                                {DAYS.map((day) => (
                                    <label
                                        key={day.value}
                                        className="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm hover:bg-accent"
                                    >
                                        <input
                                            type="checkbox"
                                            className="size-4 accent-primary"
                                            checked={selected.includes(
                                                day.value,
                                            )}
                                            onChange={() => toggle(day.value)}
                                        />
                                        {day.label}
                                    </label>
                                ))}
                            </div>
                            <Button onClick={saveWorkingDays} disabled={saving}>
                                Simpan Hari Kerja
                            </Button>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <CalendarOff className="size-4" />
                                Hari Libur
                                <span className="text-sm font-normal text-muted-foreground">
                                    ({holidays.length})
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <ul className="divide-y rounded-lg border">
                                {holidays.length === 0 ? (
                                    <li className="px-3 py-4 text-center text-sm text-muted-foreground">
                                        Belum ada hari libur
                                    </li>
                                ) : (
                                    holidays.map((holiday) => (
                                        <li
                                            key={holiday.id}
                                            className="flex items-center justify-between px-3 py-2"
                                        >
                                            <div>
                                                <p className="text-sm">
                                                    {holiday.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {formatDate(holiday.date)}
                                                </p>
                                            </div>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-7 text-muted-foreground hover:text-destructive"
                                                onClick={() =>
                                                    removeHoliday(holiday.id)
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </li>
                                    ))
                                )}
                            </ul>

                            <Form
                                action={storeHoliday.url()}
                                method="post"
                                resetOnSuccess
                                options={{ preserveScroll: true }}
                            >
                                {({ errors, processing }) => (
                                    <div className="space-y-1.5">
                                        <div className="flex gap-2">
                                            <Input type="date" name="date" />
                                            <Input
                                                name="name"
                                                placeholder="Nama libur"
                                            />
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                <Plus className="size-4" />
                                                Tambah
                                            </Button>
                                        </div>
                                        <InputError message={errors.date} />
                                        <InputError message={errors.name} />
                                    </div>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

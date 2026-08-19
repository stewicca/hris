import { Head, useForm } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import * as LeaveController from '@/actions/App/Http/Controllers/Leave/LeaveController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Employee } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Cuti', href: LeaveController.index.url() },
    { title: 'Tambah Pengajuan', href: LeaveController.create.url() },
];

export default function LeavesCreate({
    employees,
}: {
    employees: Pick<Employee, 'id' | 'name' | 'employee_number'>[];
}) {
    const { data, setData, post, processing, errors } = useForm({
        employee_id: '',
        type: 'annual',
        start_date: '',
        end_date: '',
        reason: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(LeaveController.store.url());
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tambah Pengajuan Cuti" />

            <div className="p-6">
                <div className="mx-auto max-w-xl">
                    <div className="mb-6 flex items-center gap-2">
                        <CalendarDays className="size-5 text-muted-foreground" />
                        <h1 className="text-xl font-semibold tracking-tight">
                            Tambah Pengajuan Cuti
                        </h1>
                    </div>

                    <form
                        onSubmit={submit}
                        className="space-y-5 rounded-lg border bg-card p-6"
                    >
                        {/* Karyawan */}
                        <div className="grid gap-2">
                            <Label htmlFor="employee_id">Karyawan</Label>
                            <select
                                id="employee_id"
                                value={data.employee_id}
                                onChange={(e) =>
                                    setData('employee_id', e.target.value)
                                }
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <option value="">Pilih karyawan...</option>
                                {employees.map((e) => (
                                    <option key={e.id} value={e.id}>
                                        {e.name} ({e.employee_number})
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.employee_id} />
                        </div>

                        {/* Jenis Cuti */}
                        <div className="grid gap-2">
                            <Label htmlFor="type">Jenis Cuti</Label>
                            <select
                                id="type"
                                value={data.type}
                                onChange={(e) =>
                                    setData('type', e.target.value)
                                }
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <option value="annual">Cuti Tahunan</option>
                                <option value="sick">Cuti Sakit</option>
                                <option value="permit">Izin</option>
                            </select>
                            <InputError message={errors.type} />
                        </div>

                        {/* Tanggal */}
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="start_date">
                                    Tanggal Mulai
                                </Label>
                                <Input
                                    id="start_date"
                                    type="date"
                                    value={data.start_date}
                                    onChange={(e) =>
                                        setData('start_date', e.target.value)
                                    }
                                />
                                <InputError message={errors.start_date} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="end_date">
                                    Tanggal Selesai
                                </Label>
                                <Input
                                    id="end_date"
                                    type="date"
                                    value={data.end_date}
                                    min={data.start_date}
                                    onChange={(e) =>
                                        setData('end_date', e.target.value)
                                    }
                                />
                                <InputError message={errors.end_date} />
                            </div>
                        </div>

                        {/* Alasan */}
                        <div className="grid gap-2">
                            <Label htmlFor="reason">Alasan</Label>
                            <textarea
                                id="reason"
                                value={data.reason}
                                onChange={(e) =>
                                    setData('reason', e.target.value)
                                }
                                rows={3}
                                maxLength={500}
                                placeholder="Tuliskan alasan pengajuan cuti..."
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            />
                            <InputError message={errors.reason} />
                        </div>

                        <div className="flex justify-end gap-3 pt-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => window.history.back()}
                            >
                                Batal
                            </Button>
                            <Button type="submit" disabled={processing}>
                                Simpan Pengajuan
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}

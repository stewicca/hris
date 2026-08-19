import { Form, Link, usePage } from '@inertiajs/react';
import EmployeeController from '@/actions/App/Http/Controllers/Employees/EmployeeController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { create, index } from '@/routes/employees';
import type { BreadcrumbItem, Department, Position, Shift } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Karyawan', href: index.url() },
    { title: 'Tambah Karyawan', href: create.url() },
];

const selectClass =
    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50';

export default function EmployeesCreate({
    departments,
    positions,
    shifts,
}: {
    departments: Department[];
    positions: Position[];
    shifts: Shift[];
}) {
    const { features } = usePage().props;
    const leaveEnabled = features?.leave !== false;
    const shiftEnabled = features?.shift !== false;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="space-y-6 p-6">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        Tambah Karyawan
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Data karyawan baru beserta akun pengguna akan dibuat
                        secara otomatis
                    </p>
                </div>

                <div className="max-w-2xl">
                    <Form
                        {...EmployeeController.store.form()}
                        className="space-y-6"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">
                                            Nama Lengkap *
                                        </Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            autoFocus
                                            placeholder="John Doe"
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="email">Email *</Label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            required
                                            placeholder="john@example.com"
                                        />
                                        <InputError message={errors.email} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="phone">
                                            No. Telepon
                                        </Label>
                                        <Input
                                            id="phone"
                                            name="phone"
                                            type="tel"
                                            placeholder="+62 812 3456 7890"
                                        />
                                        <InputError message={errors.phone} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="bank_account_number">
                                            No. Rekening
                                        </Label>
                                        <Input
                                            id="bank_account_number"
                                            name="bank_account_number"
                                            placeholder="1234567890"
                                        />
                                        <InputError
                                            message={errors.bank_account_number}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="hire_date">
                                            Tanggal Bergabung
                                        </Label>
                                        <Input
                                            id="hire_date"
                                            name="hire_date"
                                            type="date"
                                        />
                                        <InputError
                                            message={errors.hire_date}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="department_id">
                                            Departemen
                                        </Label>
                                        <select
                                            id="department_id"
                                            name="department_id"
                                            defaultValue=""
                                            className={selectClass}
                                        >
                                            <option value="">—</option>
                                            {departments.map((d) => (
                                                <option key={d.id} value={d.id}>
                                                    {d.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={errors.department_id}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="position_id">
                                            Jabatan
                                        </Label>
                                        <select
                                            id="position_id"
                                            name="position_id"
                                            defaultValue=""
                                            className={selectClass}
                                        >
                                            <option value="">—</option>
                                            {positions.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={errors.position_id}
                                        />
                                    </div>

                                    {shiftEnabled && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="shift_id">
                                                Shift
                                            </Label>
                                            <select
                                                id="shift_id"
                                                name="shift_id"
                                                defaultValue=""
                                                className={selectClass}
                                            >
                                                <option value="">—</option>
                                                {shifts.map((s) => (
                                                    <option
                                                        key={s.id}
                                                        value={s.id}
                                                    >
                                                        {s.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={errors.shift_id}
                                            />
                                        </div>
                                    )}

                                    <div className="grid gap-2">
                                        <Label htmlFor="status">Status *</Label>
                                        <select
                                            id="status"
                                            name="status"
                                            defaultValue="active"
                                            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            <option value="active">
                                                Aktif
                                            </option>
                                            <option value="inactive">
                                                Nonaktif
                                            </option>
                                        </select>
                                        <InputError message={errors.status} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="is_admin">Role</Label>
                                        <select
                                            id="is_admin"
                                            name="is_admin"
                                            defaultValue="0"
                                            className={selectClass}
                                        >
                                            <option value="0">Karyawan</option>
                                            <option value="1">Admin</option>
                                        </select>
                                        <InputError message={errors.is_admin} />
                                    </div>

                                    {leaveEnabled && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="annual_leave_quota">
                                                Kuota Cuti Tahunan (hari)
                                            </Label>
                                            <Input
                                                id="annual_leave_quota"
                                                name="annual_leave_quota"
                                                type="number"
                                                min={0}
                                                max={365}
                                                defaultValue={12}
                                            />
                                            <InputError
                                                message={
                                                    errors.annual_leave_quota
                                                }
                                            />
                                        </div>
                                    )}
                                </div>

                                <div className="flex items-center gap-3">
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Menyimpan...'
                                            : 'Simpan Karyawan'}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        render={<Link href={index.url()} />}
                                    >
                                        Batal
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </div>
        </AppLayout>
    );
}

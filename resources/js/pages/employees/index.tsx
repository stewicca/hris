import { Form, Link, router } from '@inertiajs/react';
import { Copy, CopyCheck, Key, Pencil, PlusCircle, Trash2 } from 'lucide-react';
import { useState } from 'react';
import EmployeeController from '@/actions/App/Http/Controllers/Employees/EmployeeController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { create, edit, index, show } from '@/routes/employees';
import type { BreadcrumbItem, Employee, PaginatedEmployees } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Karyawan', href: index.url() },
];

function CopyPasswordBanner({ password }: { password: string }) {
    const [copied, setCopied] = useState(false);

    const handleCopy = () => {
        navigator.clipboard.writeText(password);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className="flex items-center justify-between rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950">
            <div>
                <p className="text-sm font-medium text-green-800 dark:text-green-200">
                    Karyawan berhasil dibuat. Password akun:
                </p>
                <p className="mt-1 font-mono text-lg font-bold text-green-900 dark:text-green-100">
                    {password}
                </p>
                <p className="mt-0.5 text-xs text-green-700 dark:text-green-300">
                    Simpan password ini sekarang — tidak akan ditampilkan lagi.
                </p>
            </div>
            <Button
                variant="outline"
                size="sm"
                onClick={handleCopy}
                className="shrink-0"
            >
                {copied ? (
                    <>
                        <CopyCheck className="size-4" /> Tersalin
                    </>
                ) : (
                    <>
                        <Copy className="size-4" /> Salin
                    </>
                )}
            </Button>
        </div>
    );
}

function DeleteEmployeeDialog({ employee }: { employee: Employee }) {
    return (
        <Dialog>
            <DialogTrigger
                render={
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-destructive hover:text-destructive"
                    />
                }
            >
                <Trash2 className="size-4" />
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Hapus karyawan?</DialogTitle>
                <DialogDescription>
                    Tindakan ini tidak dapat dibatalkan. Data karyawan{' '}
                    <strong>{employee.name}</strong> ({employee.employee_number}
                    ) akan dihapus secara permanen.
                </DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose render={<Button variant="secondary" />}>
                        Batal
                    </DialogClose>
                    <Form {...EmployeeController.destroy.form(employee)}>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                Hapus
                            </Button>
                        )}
                    </Form>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ResetPasswordDialog({ employee }: { employee: Employee }) {
    return (
        <Dialog>
            <DialogTrigger
                render={
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-amber-600 hover:bg-amber-50 hover:text-amber-700 dark:text-amber-500 dark:hover:bg-amber-950"
                        title="Reset Password"
                    />
                }
            >
                <Key className="size-4" />
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Reset password karyawan?</DialogTitle>
                <DialogDescription>
                    Sistem akan membuatkan password baru secara acak untuk{' '}
                    <strong>{employee.name}</strong>. Password lama tidak akan
                    bisa digunakan lagi.
                </DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose render={<Button variant="secondary" />}>
                        Batal
                    </DialogClose>
                    <Form
                        {...(EmployeeController as any).resetPassword.form(
                            employee,
                        )}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                className="bg-amber-600 hover:bg-amber-700"
                                disabled={processing}
                            >
                                Reset & Generate Baru
                            </Button>
                        )}
                    </Form>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function EmployeesIndex({
    employees,
    generatedPassword,
}: {
    employees: PaginatedEmployees;
    generatedPassword?: string | null;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            Karyawan
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Kelola data karyawan perusahaan
                        </p>
                    </div>
                    <Button render={<Link href={create.url()} prefetch />}>
                        <PlusCircle className="size-4" />
                        Tambah Karyawan
                    </Button>
                </div>

                {generatedPassword && (
                    <CopyPasswordBanner password={generatedPassword} />
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    No. Karyawan
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Username
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Nama
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Email
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Departemen
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Jabatan
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                                    Status
                                </th>
                                <th className="px-4 py-3 text-right font-medium text-muted-foreground">
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {employees.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={8}
                                        className="px-4 py-10 text-center text-muted-foreground"
                                    >
                                        Belum ada karyawan. Tambah karyawan
                                        pertama Anda.
                                    </td>
                                </tr>
                            ) : (
                                employees.data.map((employee) => (
                                    <tr
                                        key={employee.id}
                                        className="border-b last:border-0 hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                                            {employee.employee_number}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs font-medium text-foreground">
                                                {employee.user
                                                    ? employee.user.username ||
                                                      'EMPTY'
                                                    : 'NO USER'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 font-medium">
                                            <div className="flex items-center gap-2">
                                                <Link
                                                    href={show.url(employee)}
                                                    className="hover:underline"
                                                    prefetch
                                                >
                                                    {employee.name}
                                                </Link>
                                                {employee.user?.is_admin && (
                                                    <Badge
                                                        variant="secondary"
                                                        className="bg-primary/10 text-primary"
                                                    >
                                                        Admin
                                                    </Badge>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {employee.email}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {employee.department?.name ?? '-'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {employee.position?.name ?? '-'}
                                        </td>
                                        <td className="px-4 py-3">
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
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    render={
                                                        <Link
                                                            href={edit.url(
                                                                employee,
                                                            )}
                                                            prefetch
                                                        />
                                                    }
                                                >
                                                    <Pencil className="size-4" />
                                                </Button>
                                                <ResetPasswordDialog
                                                    employee={employee}
                                                />
                                                <DeleteEmployeeDialog
                                                    employee={employee}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {employees.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <span>
                            Menampilkan {employees.from}–{employees.to} dari{' '}
                            {employees.total} karyawan
                        </span>
                        <div className="flex gap-1">
                            {employees.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url && router.visit(link.url)
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

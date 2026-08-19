import { Form, router } from '@inertiajs/react';
import { Building2, Plus, Trash2, UserRound } from 'lucide-react';
import {
    destroyDepartment,
    destroyPosition,
    index as masterDataIndex,
    storeDepartment,
    storePosition,
} from '@/actions/App/Http/Controllers/MasterData/MasterDataController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Department, Position } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Master Data', href: masterDataIndex.url() },
];

type Item = Department | Position;

function remove(url: string) {
    if (
        confirm('Hapus item ini? Karyawan terkait akan kehilangan tautannya.')
    ) {
        router.delete(url, { preserveScroll: true });
    }
}

function MasterList({
    title,
    icon,
    items,
    storeUrl,
    destroyUrl,
    placeholder,
}: {
    title: string;
    icon: React.ReactNode;
    items: Item[];
    storeUrl: string;
    destroyUrl: (id: number) => string;
    placeholder: string;
}) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-base font-semibold">
                    {icon}
                    {title}
                    <span className="text-sm font-normal text-muted-foreground">
                        ({items.length})
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                <ul className="divide-y rounded-lg border">
                    {items.length === 0 ? (
                        <li className="px-3 py-4 text-center text-sm text-muted-foreground">
                            Belum ada data
                        </li>
                    ) : (
                        items.map((item) => (
                            <li
                                key={item.id}
                                className="flex items-center justify-between px-3 py-2"
                            >
                                <span className="text-sm">{item.name}</span>
                                <div className="flex items-center gap-2">
                                    <span className="text-xs text-muted-foreground">
                                        {item.employees_count ?? 0} karyawan
                                    </span>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="size-7 text-muted-foreground hover:text-destructive"
                                        onClick={() =>
                                            remove(destroyUrl(item.id))
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            </li>
                        ))
                    )}
                </ul>

                <Form
                    action={storeUrl}
                    method="post"
                    resetOnSuccess
                    options={{ preserveScroll: true }}
                >
                    {({ errors, processing }) => (
                        <div className="space-y-1.5">
                            <div className="flex gap-2">
                                <Input name="name" placeholder={placeholder} />
                                <Button type="submit" disabled={processing}>
                                    <Plus className="size-4" />
                                    Tambah
                                </Button>
                            </div>
                            <InputError message={errors.name} />
                        </div>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

export default function MasterDataIndex({
    departments,
    positions,
}: {
    departments: Department[];
    positions: Position[];
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="space-y-6 p-6">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        Master Data
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Kelola daftar departemen dan jabatan
                    </p>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <MasterList
                        title="Departemen"
                        icon={<Building2 className="size-4" />}
                        items={departments}
                        storeUrl={storeDepartment.url()}
                        destroyUrl={(id) => destroyDepartment.url(id)}
                        placeholder="Departemen baru"
                    />
                    <MasterList
                        title="Jabatan"
                        icon={<UserRound className="size-4" />}
                        items={positions}
                        storeUrl={storePosition.url()}
                        destroyUrl={(id) => destroyPosition.url(id)}
                        placeholder="Jabatan baru"
                    />
                </div>
            </div>
        </AppLayout>
    );
}

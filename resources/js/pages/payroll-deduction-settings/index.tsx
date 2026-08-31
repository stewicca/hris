import { router, usePage } from '@inertiajs/react';
import {
    AlarmClock,
    CalendarX2,
    CheckCircle2,
    Coffee,
    Info,
    LogOut,
    Plus,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    index as deductionSettingsIndex,
    update,
    updateShift,
} from '@/actions/App/Http/Controllers/PayrollDeductionSettingController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Potongan Gaji', href: deductionSettingsIndex.url() },
];

type LateBasis = 'check_in' | 'late_threshold';

type Tier = {
    from_minutes: number;
    amount: number;
};

type TieredRule = {
    enabled: boolean;
    tiers: Tier[];
};

type DeductionRules = {
    late: TieredRule & { basis: LateBasis };
    early_leave: TieredRule;
    break_overrun: TieredRule;
    absent: { enabled: boolean; amount: number };
};

/** The clock a set of ladders is graded against. */
interface Schedule {
    check_in: string;
    check_out: string;
    late_threshold: string;
    grace_minutes: number;
    break_enabled: boolean;
    break_start: string | null;
    break_end: string | null;
}

interface ShiftPanel extends Schedule {
    id: number;
    name: string;
    is_active: boolean;
    employees_count: number;
    overrides: boolean;
    rules: DeductionRules;
}

interface Limits {
    max_tiers: number;
    max_from_minutes: number;
    max_amount: number;
}

interface PageProps {
    deductions: DeductionRules;
    shifts: ShiftPanel[];
    shiftMode: boolean;
    officeHours: {
        check_in: string;
        check_out: string;
        late_threshold: string;
    };
    breakWindow: { break_start: string; break_end: string };
    breakMode: boolean;
    unassignedEmployees: number | null;
    limits: Limits;
}

type FormErrors = Record<string, string | undefined>;

function formatRupiah(amount: number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(amount);
}

function sortTiers(tiers: Tier[]): Tier[] {
    return [...tiers].sort((a, b) => a.from_minutes - b.from_minutes);
}

function sortAllTiers(rules: DeductionRules): DeductionRules {
    return {
        ...rules,
        late: { ...rules.late, tiers: sortTiers(rules.late.tiers) },
        early_leave: {
            ...rules.early_leave,
            tiers: sortTiers(rules.early_leave.tiers),
        },
        break_overrun: {
            ...rules.break_overrun,
            tiers: sortTiers(rules.break_overrun.tiers),
        },
    };
}

interface TierTableProps {
    rule: TieredRule;
    prefix: keyof DeductionRules;
    /** Reads as "... terlambat", "... lebih awal". */
    unit: string;
    limits: Limits;
    errors: FormErrors;
    onChange: (tiers: Tier[]) => void;
}

/**
 * The ladder editor. Every group graded by minutes shares it, so the three
 * rules stay identical to use even though they measure different things.
 */
function TierTable({
    rule,
    prefix,
    unit,
    limits,
    errors,
    onChange,
}: TierTableProps) {
    const updateTier = (index: number, patch: Partial<Tier>) => {
        onChange(
            rule.tiers.map((tier, i) =>
                i === index ? { ...tier, ...patch } : tier,
            ),
        );
    };

    const addTier = () => {
        const last = rule.tiers[rule.tiers.length - 1];

        onChange([
            ...rule.tiers,
            {
                from_minutes: last ? last.from_minutes + 15 : 15,
                amount: last ? last.amount : 0,
            },
        ]);
    };

    const ladderError = errors[`${prefix}.tiers`];

    return (
        <div className="space-y-3">
            {rule.tiers.length === 0 ? (
                <p className="rounded-lg border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">
                    Belum ada tingkat potongan.
                </p>
            ) : (
                <div className="space-y-2">
                    <div className="grid grid-cols-[7rem_1fr_2.25rem] gap-2 px-1 text-xs font-medium text-muted-foreground">
                        <span>Mulai menit</span>
                        <span>Potongan</span>
                        <span className="sr-only">Aksi</span>
                    </div>

                    {rule.tiers.map((tier, index) => {
                        const minutesError =
                            errors[`${prefix}.tiers.${index}.from_minutes`];
                        const amountError =
                            errors[`${prefix}.tiers.${index}.amount`];

                        return (
                            <div key={index} className="space-y-1">
                                <div className="grid grid-cols-[7rem_1fr_2.25rem] items-center gap-2">
                                    <Input
                                        type="number"
                                        min={1}
                                        max={limits.max_from_minutes}
                                        aria-label={`Mulai menit tingkat ${index + 1}`}
                                        value={tier.from_minutes}
                                        onChange={(e) =>
                                            updateTier(index, {
                                                from_minutes: Number(
                                                    e.target.value,
                                                ),
                                            })
                                        }
                                    />
                                    <Input
                                        type="number"
                                        min={0}
                                        max={limits.max_amount}
                                        step={1000}
                                        aria-label={`Potongan tingkat ${index + 1}`}
                                        value={tier.amount}
                                        onChange={(e) =>
                                            updateTier(index, {
                                                amount: Number(e.target.value),
                                            })
                                        }
                                    />
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Hapus tingkat ${index + 1}`}
                                        onClick={() =>
                                            onChange(
                                                rule.tiers.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>

                                <p className="px-1 text-xs text-muted-foreground">
                                    {tier.from_minutes} menit {unit} atau lebih
                                    &rarr; potong {formatRupiah(tier.amount)}
                                </p>

                                {minutesError && (
                                    <InputError message={minutesError} />
                                )}
                                {amountError && (
                                    <InputError message={amountError} />
                                )}
                            </div>
                        );
                    })}
                </div>
            )}

            {ladderError && <InputError message={ladderError} />}

            <Button
                variant="outline"
                size="sm"
                onClick={addTier}
                disabled={rule.tiers.length >= limits.max_tiers}
            >
                <Plus className="size-4" />
                Tambah Tingkat
            </Button>

            {rule.tiers.length >= limits.max_tiers && (
                <p className="text-xs text-muted-foreground">
                    Maksimal {limits.max_tiers} tingkat.
                </p>
            )}
        </div>
    );
}

interface RuleCardProps {
    icon: React.ReactNode;
    title: string;
    description: string;
    enabled: boolean;
    onToggle: (enabled: boolean) => void;
    children: React.ReactNode;
}

function RuleCard({
    icon,
    title,
    description,
    enabled,
    onToggle,
    children,
}: RuleCardProps) {
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
                        checked={enabled}
                        onChange={(e) => onToggle(e.target.checked)}
                    />
                    Aktifkan
                </label>

                <p className="text-xs text-muted-foreground">{description}</p>

                {enabled && children}
            </CardContent>
        </Card>
    );
}

interface DeductionRuleFormProps {
    rules: DeductionRules;
    onChange: (rules: DeductionRules) => void;
    /** The clock these ladders are graded against. */
    schedule: Schedule;
    /** Break tracking actually happens for this schedule. */
    breakAvailable: boolean;
    errors: FormErrors;
    limits: Limits;
    /** Radio group names must not collide between panels. */
    scope: string;
}

function DeductionRuleForm({
    rules,
    onChange,
    schedule,
    breakAvailable,
    errors,
    limits,
    scope,
}: DeductionRuleFormProps) {
    return (
        <>
            <RuleCard
                icon={<AlarmClock className="size-4" />}
                title="Terlambat"
                description="Potongan saat karyawan check-in lebih lambat dari jadwal. Dihitung per hari."
                enabled={rules.late.enabled}
                onToggle={(enabled) =>
                    onChange({ ...rules, late: { ...rules.late, enabled } })
                }
            >
                <div className="space-y-2">
                    <p className="text-sm font-medium">
                        Keterlambatan dihitung dari
                    </p>
                    <label className="flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2 text-sm hover:bg-accent">
                        <input
                            type="radio"
                            className="mt-0.5 size-4 accent-primary"
                            name={`${scope}_late_basis`}
                            checked={rules.late.basis === 'check_in'}
                            onChange={() =>
                                onChange({
                                    ...rules,
                                    late: { ...rules.late, basis: 'check_in' },
                                })
                            }
                        />
                        <span>
                            Jam masuk ({schedule.check_in})
                            <span className="block text-xs text-muted-foreground">
                                Toleransi yang sudah diatur di jadwal ikut
                                dihitung sebagai keterlambatan.
                            </span>
                        </span>
                    </label>
                    <label className="flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2 text-sm hover:bg-accent">
                        <input
                            type="radio"
                            className="mt-0.5 size-4 accent-primary"
                            name={`${scope}_late_basis`}
                            checked={rules.late.basis === 'late_threshold'}
                            onChange={() =>
                                onChange({
                                    ...rules,
                                    late: {
                                        ...rules.late,
                                        basis: 'late_threshold',
                                    },
                                })
                            }
                        />
                        <span>
                            Batas terlambat ({schedule.late_threshold}
                            {schedule.grace_minutes > 0 &&
                                ` + ${schedule.grace_minutes} menit toleransi`}
                            )
                            <span className="block text-xs text-muted-foreground">
                                Menit baru dihitung setelah toleransi habis.
                            </span>
                        </span>
                    </label>
                    {errors['late.basis'] && (
                        <InputError message={errors['late.basis']} />
                    )}
                </div>

                <TierTable
                    rule={rules.late}
                    prefix="late"
                    unit="terlambat"
                    limits={limits}
                    errors={errors}
                    onChange={(tiers) =>
                        onChange({ ...rules, late: { ...rules.late, tiers } })
                    }
                />
            </RuleCard>

            <RuleCard
                icon={<LogOut className="size-4" />}
                title="Pulang Lebih Awal"
                description={`Potongan saat karyawan check-out sebelum jam pulang (${schedule.check_out}).`}
                enabled={rules.early_leave.enabled}
                onToggle={(enabled) =>
                    onChange({
                        ...rules,
                        early_leave: { ...rules.early_leave, enabled },
                    })
                }
            >
                <TierTable
                    rule={rules.early_leave}
                    prefix="early_leave"
                    unit="lebih awal"
                    limits={limits}
                    errors={errors}
                    onChange={(tiers) =>
                        onChange({
                            ...rules,
                            early_leave: { ...rules.early_leave, tiers },
                        })
                    }
                />
            </RuleCard>

            {/* Only meaningful where a break is actually recorded. */}
            {breakAvailable && (
                <RuleCard
                    icon={<Coffee className="size-4" />}
                    title="Kelebihan Istirahat"
                    description={`Potongan saat istirahat melewati jendela yang dijadwalkan (${schedule.break_start} – ${schedule.break_end}).`}
                    enabled={rules.break_overrun.enabled}
                    onToggle={(enabled) =>
                        onChange({
                            ...rules,
                            break_overrun: {
                                ...rules.break_overrun,
                                enabled,
                            },
                        })
                    }
                >
                    <TierTable
                        rule={rules.break_overrun}
                        prefix="break_overrun"
                        unit="melebihi istirahat"
                        limits={limits}
                        errors={errors}
                        onChange={(tiers) =>
                            onChange({
                                ...rules,
                                break_overrun: {
                                    ...rules.break_overrun,
                                    tiers,
                                },
                            })
                        }
                    />
                </RuleCard>
            )}

            {/* Absence is flat — there is no "how late" to grade. */}
            <RuleCard
                icon={<CalendarX2 className="size-4" />}
                title="Tidak Masuk (Alpa)"
                description="Potongan untuk setiap hari kerja tanpa kehadiran. Cuti dan izin yang sudah disetujui tidak dihitung alpa, jadi tidak ikut terpotong."
                enabled={rules.absent.enabled}
                onToggle={(enabled) =>
                    onChange({ ...rules, absent: { ...rules.absent, enabled } })
                }
            >
                <div className="space-y-1.5">
                    <label className="text-sm font-medium">
                        Potongan per hari
                    </label>
                    <Input
                        type="number"
                        min={0}
                        max={limits.max_amount}
                        step={1000}
                        value={rules.absent.amount}
                        onChange={(e) =>
                            onChange({
                                ...rules,
                                absent: {
                                    ...rules.absent,
                                    amount: Number(e.target.value),
                                },
                            })
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        {formatRupiah(rules.absent.amount)} untuk setiap hari
                        alpa.
                    </p>
                    {errors['absent.amount'] && (
                        <InputError message={errors['absent.amount']} />
                    )}
                </div>
            </RuleCard>
        </>
    );
}

function ScheduleCard({
    schedule,
    breakAvailable,
    note,
}: {
    schedule: Schedule;
    breakAvailable: boolean;
    note: string;
}) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-base font-semibold">
                    <Info className="size-4" />
                    Jadwal Acuan
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                <div className="grid gap-3 sm:grid-cols-4">
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Jam Masuk
                        </p>
                        <p className="font-medium">{schedule.check_in}</p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Batas Terlambat
                        </p>
                        <p className="font-medium">
                            {schedule.late_threshold}
                            {schedule.grace_minutes > 0 && (
                                <span className="text-xs font-normal text-muted-foreground">
                                    {' '}
                                    +{schedule.grace_minutes}m
                                </span>
                            )}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Jam Pulang
                        </p>
                        <p className="font-medium">{schedule.check_out}</p>
                    </div>
                    {breakAvailable && (
                        <div>
                            <p className="text-xs text-muted-foreground">
                                Istirahat
                            </p>
                            <p className="font-medium">
                                {schedule.break_start} &ndash;{' '}
                                {schedule.break_end}
                            </p>
                        </div>
                    )}
                </div>
                <p className="text-xs text-muted-foreground">{note}</p>
            </CardContent>
        </Card>
    );
}

export default function PayrollDeductionSettingsIndex({
    deductions,
    shifts,
    shiftMode,
    officeHours,
    breakWindow,
    breakMode,
    unassignedEmployees,
    limits,
}: PageProps) {
    const page = usePage();
    const flash = page.props.flash;
    const errors = (page.props.errors ?? {}) as FormErrors;

    const [toast, setToast] = useState<string | null>(null);

    useEffect(() => {
        if (flash?.success) {
            setToast(flash.success);
            const t = setTimeout(() => setToast(null), 3500);
            return () => clearTimeout(t);
        }
    }, [flash?.success]);

    const [globalRules, setGlobalRules] = useState<DeductionRules>(deductions);
    const [panels, setPanels] = useState(() =>
        shifts.map((shift) => ({
            id: shift.id,
            overrides: shift.overrides,
            rules: shift.rules,
        })),
    );
    const [active, setActive] = useState<'global' | number>('global');
    const [saving, setSaving] = useState(false);

    const globalSchedule: Schedule = {
        ...officeHours,
        // Grace is a shift-only concept; the global late threshold already
        // carries whatever tolerance the office wanted.
        grace_minutes: 0,
        break_enabled: breakMode,
        break_start: breakWindow.break_start,
        break_end: breakWindow.break_end,
    };

    const activeShift =
        active === 'global' ? null : shifts.find((s) => s.id === active);
    const activePanel =
        active === 'global' ? null : panels.find((p) => p.id === active);

    const submitGlobal = () => {
        const payload = sortAllTiers(globalRules);

        router.put(update.url(), payload, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            // The server sorts each ladder; mirroring it here keeps the rows the
            // admin is looking at in the order that was actually saved.
            onSuccess: () => setGlobalRules(payload),
        });
    };

    const submitShift = () => {
        if (!activePanel) {
            return;
        }

        const payload = sortAllTiers(activePanel.rules);

        router.put(
            updateShift.url(activePanel.id),
            {
                overrides: activePanel.overrides,
                ...payload,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () =>
                    setPanels((current) =>
                        current.map((p) =>
                            p.id === activePanel.id
                                ? { ...p, rules: payload }
                                : p,
                        ),
                    ),
            },
        );
    };

    const patchPanel = (
        id: number,
        patch: Partial<(typeof panels)[number]>,
    ) => {
        setPanels((current) =>
            current.map((p) => (p.id === id ? { ...p, ...patch } : p)),
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
                        Potongan Gaji
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Atur potongan otomatis berdasarkan kehadiran: terlambat,
                        pulang lebih awal, kelebihan istirahat, dan tidak masuk.
                        Setiap aturan bertingkat — hanya tingkat tertinggi yang
                        tercapai yang dipotong, tidak diakumulasi.
                    </p>
                </div>

                {/* Scope switcher. Present only when shifts can actually apply. */}
                {shiftMode && shifts.length > 0 && (
                    <div className="flex flex-wrap gap-2 border-b pb-3">
                        <button
                            type="button"
                            onClick={() => setActive('global')}
                            className={cn(
                                'rounded-lg border px-3 py-1.5 text-sm transition-colors',
                                active === 'global'
                                    ? 'border-primary bg-primary/10 font-medium'
                                    : 'hover:bg-accent',
                            )}
                        >
                            Aturan Global
                        </button>
                        {shifts.map((shift) => {
                            const panel = panels.find((p) => p.id === shift.id);

                            return (
                                <button
                                    key={shift.id}
                                    type="button"
                                    onClick={() => setActive(shift.id)}
                                    className={cn(
                                        'rounded-lg border px-3 py-1.5 text-sm transition-colors',
                                        active === shift.id
                                            ? 'border-primary bg-primary/10 font-medium'
                                            : 'hover:bg-accent',
                                    )}
                                >
                                    {shift.name}
                                    <span className="ml-2 text-xs text-muted-foreground">
                                        {panel?.overrides
                                            ? 'aturan sendiri'
                                            : 'ikut global'}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                )}

                {active === 'global' ? (
                    <>
                        <ScheduleCard
                            schedule={globalSchedule}
                            breakAvailable={breakMode}
                            note={
                                shiftMode
                                    ? 'Berlaku untuk karyawan yang tidak punya shift. Karyawan bershift memakai jam shift-nya masing-masing, dan aturan potongannya bisa ditimpa per shift lewat tab di atas.'
                                    : 'Diambil dari Pengaturan Absensi. Ubah jadwalnya di sana, bukan di halaman ini.'
                            }
                        />

                        {shiftMode && unassignedEmployees === 0 && (
                            <div className="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400">
                                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                <span>
                                    Semua karyawan aktif sudah punya shift, jadi
                                    aturan global ini tidak dipakai siapa pun
                                    saat ini. Atur potongannya di tab shift.
                                </span>
                            </div>
                        )}

                        <DeductionRuleForm
                            rules={globalRules}
                            onChange={setGlobalRules}
                            schedule={globalSchedule}
                            breakAvailable={breakMode}
                            errors={errors}
                            limits={limits}
                            scope="global"
                        />

                        <Button onClick={submitGlobal} disabled={saving}>
                            Simpan Aturan Global
                        </Button>
                    </>
                ) : (
                    activeShift &&
                    activePanel && (
                        <>
                            <ScheduleCard
                                schedule={activeShift}
                                breakAvailable={
                                    breakMode && activeShift.break_enabled
                                }
                                note={`Jam shift ${activeShift.name}, dipakai oleh ${activeShift.employees_count} karyawan. Ubah jamnya di halaman Shift.`}
                            />

                            <Card>
                                <CardContent className="space-y-3 pt-6">
                                    <label className="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm hover:bg-accent">
                                        <input
                                            type="checkbox"
                                            className="size-4 accent-primary"
                                            checked={activePanel.overrides}
                                            onChange={(e) =>
                                                patchPanel(activePanel.id, {
                                                    overrides: e.target.checked,
                                                })
                                            }
                                        />
                                        Gunakan aturan potongan sendiri untuk
                                        shift ini
                                    </label>
                                    <p className="text-xs text-muted-foreground">
                                        {activePanel.overrides
                                            ? 'Shift ini memakai aturan di bawah. Perubahan pada aturan global tidak lagi mempengaruhinya.'
                                            : 'Shift ini mengikuti Aturan Global. Centang untuk memberinya aturan sendiri — nilainya dimulai dari salinan aturan global saat ini.'}
                                    </p>
                                </CardContent>
                            </Card>

                            {activePanel.overrides && (
                                <>
                                    {breakMode &&
                                        !activeShift.break_enabled && (
                                            <div className="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm text-muted-foreground">
                                                <Info className="mt-0.5 size-4 shrink-0" />
                                                <span>
                                                    Shift ini tidak mencatat
                                                    istirahat, jadi potongan
                                                    kelebihan istirahat tidak
                                                    berlaku dan disembunyikan.
                                                </span>
                                            </div>
                                        )}

                                    <DeductionRuleForm
                                        rules={activePanel.rules}
                                        onChange={(rules) =>
                                            patchPanel(activePanel.id, {
                                                rules,
                                            })
                                        }
                                        schedule={activeShift}
                                        breakAvailable={
                                            breakMode &&
                                            activeShift.break_enabled
                                        }
                                        errors={errors}
                                        limits={limits}
                                        scope={`shift_${activeShift.id}`}
                                    />
                                </>
                            )}

                            <Button onClick={submitShift} disabled={saving}>
                                Simpan Potongan {activeShift.name}
                            </Button>
                        </>
                    )
                )}
            </div>
        </AppLayout>
    );
}

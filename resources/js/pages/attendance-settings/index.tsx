import { router, usePage } from '@inertiajs/react';
import { Clock, MapPin, Navigation, CheckCircle2, Coffee } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    index as settingsIndex,
    updateBreak,
    updateHours,
    updateLocation,
} from '@/actions/App/Http/Controllers/AttendanceSettingController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pengaturan Absensi', href: settingsIndex.url() },
];

interface OfficeHours {
    check_in: string;
    check_out: string;
    late_threshold: string;
}

interface OfficeLocation {
    latitude: number | null;
    longitude: number | null;
    radius_meters: number;
}

interface PageProps {
    officeHours: OfficeHours;
    officeLocation: OfficeLocation;
    geofenceEnabled: boolean;
    breakWindow: { break_start: string; break_end: string };
}

export default function AttendanceSettingsIndex({
    officeHours,
    officeLocation,
    geofenceEnabled,
    breakWindow,
}: PageProps) {
    const flash = usePage().props.flash;
    const { features } = usePage().props;
    const breakEnabled = features?.break !== false;
    const [toast, setToast] = useState<string | null>(null);

    useEffect(() => {
        if (flash?.success) {
            setToast(flash.success);
            const t = setTimeout(() => setToast(null), 3500);
            return () => clearTimeout(t);
        }
    }, [flash?.success]);

    // --- Office hours form state ---
    const [hours, setHours] = useState(officeHours);
    const [savingHours, setSavingHours] = useState(false);

    const submitHours = () => {
        router.put(
            updateHours.url(),
            {
                check_in: hours.check_in,
                check_out: hours.check_out,
                late_threshold: hours.late_threshold,
            },
            {
                preserveScroll: true,
                onStart: () => setSavingHours(true),
                onFinish: () => setSavingHours(false),
            },
        );
    };

    // --- Office location form state ---
    const [enableGeofence, setEnableGeofence] = useState(geofenceEnabled);
    const [location, setLocation] = useState({
        latitude: officeLocation.latitude ?? '',
        longitude: officeLocation.longitude ?? '',
        radius_meters: officeLocation.radius_meters,
    });
    const [locating, setLocating] = useState(false);
    const [locationError, setLocationError] = useState<string | null>(null);
    const [savingLocation, setSavingLocation] = useState(false);

    const useCurrentLocation = () => {
        if (!navigator.geolocation) {
            setLocationError('Browser tidak mendukung GPS.');
            return;
        }
        setLocating(true);
        setLocationError(null);
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                setLocation((prev) => ({
                    ...prev,
                    latitude: String(pos.coords.latitude),
                    longitude: String(pos.coords.longitude),
                }));
                setEnableGeofence(true);
                setLocating(false);
            },
            (err) => {
                setLocationError(
                    err.code === err.PERMISSION_DENIED
                        ? 'Izin lokasi ditolak. Aktifkan di pengaturan browser.'
                        : 'Gagal mendapatkan lokasi. Coba lagi.',
                );
                setLocating(false);
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
        );
    };

    const submitLocation = () => {
        router.put(
            updateLocation.url(),
            {
                enable_geofence: enableGeofence,
                latitude: location.latitude || null,
                longitude: location.longitude || null,
                radius_meters: location.radius_meters,
            },
            {
                preserveScroll: true,
                onStart: () => setSavingLocation(true),
                onFinish: () => setSavingLocation(false),
            },
        );
    };

    // --- Break window form state (global; used when shift mode is off) ---
    const [breakHours, setBreakHours] = useState(breakWindow);
    const [savingBreak, setSavingBreak] = useState(false);

    const submitBreak = () => {
        router.put(
            updateBreak.url(),
            {
                break_start: breakHours.break_start,
                break_end: breakHours.break_end,
            },
            {
                preserveScroll: true,
                onStart: () => setSavingBreak(true),
                onFinish: () => setSavingBreak(false),
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
                        Pengaturan Absensi
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Atur jam kerja (jam masuk &amp; pulang), batas
                        terlambat, dan lokasi kantor untuk validasi GPS absensi.
                    </p>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Office Hours */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <Clock className="size-4" />
                                Jam Kerja
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <label className="text-sm font-medium">
                                        Jam Masuk
                                    </label>
                                    <Input
                                        type="time"
                                        value={hours.check_in}
                                        onChange={(e) =>
                                            setHours((h) => ({
                                                ...h,
                                                check_in: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <label className="text-sm font-medium">
                                        Jam Pulang
                                    </label>
                                    <Input
                                        type="time"
                                        value={hours.check_out}
                                        onChange={(e) =>
                                            setHours((h) => ({
                                                ...h,
                                                check_out: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <label className="text-sm font-medium">
                                    Batas Terlambat
                                </label>
                                <Input
                                    type="time"
                                    value={hours.late_threshold}
                                    onChange={(e) =>
                                        setHours((h) => ({
                                            ...h,
                                            late_threshold: e.target.value,
                                        }))
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Karyawan yang check-in setelah jam ini akan
                                    ditandai terlambat.
                                </p>
                            </div>

                            <Button
                                onClick={submitHours}
                                disabled={savingHours}
                            >
                                Simpan Jam Kerja
                            </Button>
                        </CardContent>
                    </Card>

                    {/* Office Location */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <MapPin className="size-4" />
                                Lokasi Kantor (Geofence)
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <label className="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm hover:bg-accent">
                                <input
                                    type="checkbox"
                                    className="size-4 accent-primary"
                                    checked={enableGeofence}
                                    onChange={(e) =>
                                        setEnableGeofence(e.target.checked)
                                    }
                                />
                                Aktifkan validasi lokasi saat absen
                            </label>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <label className="text-sm font-medium">
                                        Latitude
                                    </label>
                                    <Input
                                        type="number"
                                        step="any"
                                        placeholder="-6.2088"
                                        value={location.latitude}
                                        disabled={!enableGeofence}
                                        onChange={(e) =>
                                            setLocation((l) => ({
                                                ...l,
                                                latitude: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <label className="text-sm font-medium">
                                        Longitude
                                    </label>
                                    <Input
                                        type="number"
                                        step="any"
                                        placeholder="106.8456"
                                        value={location.longitude}
                                        disabled={!enableGeofence}
                                        onChange={(e) =>
                                            setLocation((l) => ({
                                                ...l,
                                                longitude: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <label className="text-sm font-medium">
                                    Radius (meter)
                                </label>
                                <Input
                                    type="number"
                                    min={10}
                                    max={5000}
                                    value={location.radius_meters}
                                    onChange={(e) =>
                                        setLocation((l) => ({
                                            ...l,
                                            radius_meters: Number(
                                                e.target.value,
                                            ),
                                        }))
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Absensi hanya diterima dalam radius ini dari
                                    lokasi kantor.
                                </p>
                            </div>

                            <Button
                                variant="outline"
                                onClick={useCurrentLocation}
                                disabled={!enableGeofence || locating}
                            >
                                <Navigation className="size-4" />
                                {locating
                                    ? 'Mengambil lokasi...'
                                    : 'Gunakan Lokasi Saya'}
                            </Button>
                            {locationError && (
                                <InputError message={locationError} />
                            )}

                            <Button
                                onClick={submitLocation}
                                disabled={savingLocation}
                            >
                                Simpan Lokasi Kantor
                            </Button>
                        </CardContent>
                    </Card>
                </div>

                {/* Break window (global; shown only when the break feature is on.
                    When shift mode is also on, each shift owns its own break
                    window configured in the Shift editor instead.) */}
                {breakEnabled && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <Coffee className="size-4" />
                                Jam Istirahat
                                {features?.shift !== false && (
                                    <span className="text-xs font-normal text-muted-foreground">
                                        (berlaku ketika mode shift nonaktif)
                                    </span>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <label className="text-sm font-medium">
                                        Istirahat Mulai
                                    </label>
                                    <Input
                                        type="time"
                                        value={breakHours.break_start}
                                        onChange={(e) =>
                                            setBreakHours((b) => ({
                                                ...b,
                                                break_start: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <label className="text-sm font-medium">
                                        Istirahat Selesai
                                    </label>
                                    <Input
                                        type="time"
                                        value={breakHours.break_end}
                                        onChange={(e) =>
                                            setBreakHours((b) => ({
                                                ...b,
                                                break_end: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {features?.shift !== false
                                    ? 'Saat mode shift aktif, jendela istirahat diatur per-shift di halaman Shift. Pengaturan ini hanya berlaku untuk karyawan tanpa penugasan shift.'
                                    : 'Durasi istirahat akan dikurangkan dari total jam kerja karyawan. Karyawan dapat melewatkan istirahat (opsional).'}
                            </p>
                            <Button
                                onClick={submitBreak}
                                disabled={savingBreak}
                            >
                                Simpan Jam Istirahat
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}

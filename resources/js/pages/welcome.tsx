import { Head, Link } from '@inertiajs/react';
import { usePage } from '@inertiajs/react';
import {
    BarChart3,
    Calendar,
    CheckCircle2,
    Clock,
    DollarSign,
    FileText,
    Shield,
    Users,
} from 'lucide-react';
import { dashboard, login } from '@/routes';

const features = [
    {
        icon: Users,
        title: 'Manajemen Karyawan',
        description:
            'Kelola data karyawan secara terpusat. Mulai dari profil, jabatan, hingga riwayat pekerjaan.',
    },
    {
        icon: Clock,
        title: 'Absensi & Kehadiran',
        description:
            'Pantau kehadiran karyawan secara real-time dengan laporan yang mudah dipahami.',
    },
    {
        icon: Calendar,
        title: 'Manajemen Cuti',
        description:
            'Ajukan, setujui, dan lacak cuti karyawan dengan alur persetujuan yang fleksibel.',
    },
    {
        icon: DollarSign,
        title: 'Penggajian',
        description:
            'Proses penggajian otomatis dengan perhitungan tunjangan, potongan, dan pajak yang akurat.',
    },
    {
        icon: BarChart3,
        title: 'Laporan & Analitik',
        description:
            'Buat laporan SDM yang komprehensif untuk mendukung pengambilan keputusan bisnis.',
    },
    {
        icon: Shield,
        title: 'Keamanan Data',
        description:
            'Keamanan data karyawan terjamin dengan kontrol akses berbasis peran dan enkripsi data.',
    },
];

const highlights = [
    'Akses dari mana saja, kapan saja',
    'Antarmuka yang mudah digunakan',
    'Laporan otomatis & real-time',
    'Dukungan multi-cabang & departemen',
    'Integrasi dengan sistem eksternal',
    'Data aman & terenkripsi',
];

export default function Welcome() {
    const { auth } = usePage<{ auth: { user: { name: string } | null } }>()
        .props;

    return (
        <>
            <Head title="Selamat Datang di HRIS" />

            <div className="min-h-screen bg-background text-foreground">
                {/* Navbar */}
                <header className="sticky top-0 z-50 border-b border-border bg-background/80 backdrop-blur-sm">
                    <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary">
                                <Users className="h-4 w-4 text-primary-foreground" />
                            </div>
                            <span className="text-lg font-semibold tracking-tight">
                                HRIS
                            </span>
                        </div>

                        <nav className="flex items-center gap-3">
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
                                >
                                    Ke Dashboard
                                </Link>
                            ) : (
                                <Link
                                    href={login()}
                                    className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
                                >
                                    Masuk
                                </Link>
                            )}
                        </nav>
                    </div>
                </header>

                {/* Hero */}
                <section className="mx-auto max-w-7xl px-6 py-24 text-center">
                    <div className="mb-8 inline-flex items-center gap-2 rounded-full border border-border bg-muted px-4 py-1.5 text-sm text-muted-foreground">
                        <span className="h-1.5 w-1.5 rounded-full bg-primary" />
                        Sistem Informasi Sumber Daya Manusia
                    </div>

                    <h1 className="mx-auto max-w-3xl text-4xl font-bold tracking-tight text-foreground sm:text-5xl lg:text-6xl">
                        Kelola SDM Perusahaan Anda dengan{' '}
                        <span className="text-primary">Lebih Efisien</span>
                    </h1>

                    <p className="mx-auto mt-6 max-w-2xl text-lg text-muted-foreground">
                        Platform HRIS terpadu untuk mengelola karyawan, absensi,
                        cuti, penggajian, dan laporan SDM dalam satu sistem yang
                        terintegrasi.
                    </p>

                    <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
                        {auth.user ? (
                            <Link
                                href={dashboard()}
                                className="rounded-lg bg-primary px-8 py-3 text-base font-semibold text-primary-foreground shadow-sm transition-opacity hover:opacity-90"
                            >
                                Buka Dashboard
                            </Link>
                        ) : (
                            <Link
                                href={login()}
                                className="rounded-lg bg-primary px-8 py-3 text-base font-semibold text-primary-foreground shadow-sm transition-opacity hover:opacity-90"
                            >
                                Mulai Sekarang
                            </Link>
                        )}
                    </div>
                </section>

                {/* Features */}
                <section className="border-y border-border bg-muted/40 py-24">
                    <div className="mx-auto max-w-7xl px-6">
                        <div className="mb-16 text-center">
                            <h2 className="text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
                                Semua yang Anda Butuhkan
                            </h2>
                            <p className="mt-4 text-lg text-muted-foreground">
                                Fitur lengkap untuk mengelola seluruh siklus
                                kehidupan karyawan di perusahaan Anda.
                            </p>
                        </div>

                        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {features.map((feature) => (
                                <div
                                    key={feature.title}
                                    className="rounded-xl border border-border bg-card p-6 shadow-sm transition-shadow hover:shadow-md"
                                >
                                    <div className="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                                        <feature.icon className="h-5 w-5 text-primary" />
                                    </div>
                                    <h3 className="mb-2 text-base font-semibold text-card-foreground">
                                        {feature.title}
                                    </h3>
                                    <p className="text-sm leading-relaxed text-muted-foreground">
                                        {feature.description}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                {/* Highlights */}
                <section className="py-24">
                    <div className="mx-auto max-w-7xl px-6">
                        <div className="grid grid-cols-1 gap-16 lg:grid-cols-2 lg:items-center">
                            <div>
                                <h2 className="text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
                                    Dirancang untuk Tim HR Modern
                                </h2>
                                <p className="mt-4 text-lg text-muted-foreground">
                                    HRIS kami membantu tim HR bekerja lebih
                                    cerdas, bukan lebih keras. Otomatisasi tugas
                                    repetitif dan fokus pada hal yang
                                    benar-benar penting.
                                </p>

                                <ul className="mt-8 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    {highlights.map((item) => (
                                        <li
                                            key={item}
                                            className="flex items-center gap-3 text-sm text-foreground"
                                        >
                                            <CheckCircle2 className="h-4 w-4 shrink-0 text-primary" />
                                            {item}
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                {[
                                    {
                                        label: 'Karyawan Terkelola',
                                        value: '500+',
                                        icon: Users,
                                    },
                                    {
                                        label: 'Laporan Dibuat',
                                        value: '1.200+',
                                        icon: FileText,
                                    },
                                    {
                                        label: 'Akurasi Penggajian',
                                        value: '99,9%',
                                        icon: DollarSign,
                                    },
                                    {
                                        label: 'Uptime Sistem',
                                        value: '99,5%',
                                        icon: BarChart3,
                                    },
                                ].map((stat) => (
                                    <div
                                        key={stat.label}
                                        className="rounded-xl border border-border bg-card p-6 text-center shadow-sm"
                                    >
                                        <stat.icon className="mx-auto mb-3 h-6 w-6 text-primary" />
                                        <div className="text-2xl font-bold text-card-foreground">
                                            {stat.value}
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {stat.label}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </section>

                {/* CTA */}
                <section className="border-t border-border bg-primary py-20">
                    <div className="mx-auto max-w-7xl px-6 text-center">
                        <h2 className="text-3xl font-bold tracking-tight text-primary-foreground sm:text-4xl">
                            Siap Memulai?
                        </h2>
                        <p className="mt-4 text-lg text-primary-foreground/80">
                            Masuk ke sistem dan mulai kelola sumber daya manusia
                            perusahaan Anda.
                        </p>
                        <div className="mt-8">
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className="rounded-lg bg-background px-8 py-3 text-base font-semibold text-foreground shadow-sm transition-opacity hover:opacity-90"
                                >
                                    Buka Dashboard
                                </Link>
                            ) : (
                                <Link
                                    href={login()}
                                    className="rounded-lg bg-background px-8 py-3 text-base font-semibold text-foreground shadow-sm transition-opacity hover:opacity-90"
                                >
                                    Masuk ke HRIS
                                </Link>
                            )}
                        </div>
                    </div>
                </section>

                {/* Footer */}
                <footer className="border-t border-border py-8">
                    <div className="mx-auto max-w-7xl px-6 text-center text-sm text-muted-foreground">
                        © {new Date().getFullYear()} HRIS. Hak cipta dilindungi.
                    </div>
                </footer>
            </div>
        </>
    );
}

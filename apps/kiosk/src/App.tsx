import { CheckCircle2, KeyRound, ScanFace, TriangleAlert, WifiOff } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import {
    clearToken,
    fetchSettings,
    identify,
    KioskApiError,
    recordScan,
    storedToken,
    storeToken,
} from './api';
import type { IdentifyResult, KioskSettings } from './api';
import { CameraStage } from './components/CameraStage';

type Stage =
    | { name: 'booting' }
    | { name: 'pairing'; error: string | null }
    | { name: 'unavailable'; message: string }
    | { name: 'idle' }
    | { name: 'identifying' }
    | { name: 'confirm'; scan: IdentifyResult; expiresAt: number }
    | { name: 'recording' }
    | { name: 'success'; message: string }
    | { name: 'failure'; message: string };

/** How long a name stays on screen waiting to be confirmed. */
const CONFIRM_SECONDS = 15;

/** How long a result is shown before the terminal resets for the next person. */
const RESULT_MS = 4000;

/** How often the terminal re-checks that the face service is still answering. */
const HEALTH_POLL_MS = 60_000;

export default function App() {
    // Resolved up front so the first render is already the right screen: the
    // pairing form when no token is stored, otherwise a boot placeholder.
    const [stage, setStage] = useState<Stage>(() =>
        storedToken() ? { name: 'booting' } : { name: 'pairing', error: null },
    );
    const [settings, setSettings] = useState<KioskSettings | null>(null);
    const [now, setNow] = useState(() => new Date());
    const stageRef = useRef(stage);

    // The capture callback lives outside the render that produced it, so it
    // reads the stage through a ref rather than a captured value — otherwise a
    // frame captured just before a transition would be handled as if the
    // terminal were still idle.
    useEffect(() => {
        stageRef.current = stage;
    }, [stage]);

    // Only ever called with a token present — the initial state covers the
    // unpaired terminal, and both callers (pairing, retry) run after one is
    // stored. A token that has since been revoked comes back as a 401 below.
    const boot = useCallback(async () => {
        try {
            const loaded = await fetchSettings();
            setSettings(loaded);
            setStage({ name: 'idle' });
        } catch (error) {
            if (error instanceof KioskApiError && error.status === 401) {
                setStage({ name: 'pairing', error: 'Token terminal tidak dikenali. Masukkan token yang baru.' });

                return;
            }

            if (error instanceof KioskApiError && error.status === 403) {
                setStage({ name: 'unavailable', message: 'Terminal ini tidak diizinkan dari jaringan saat ini.' });

                return;
            }

            if (error instanceof KioskApiError && error.status === 404) {
                setStage({ name: 'unavailable', message: 'Fitur terminal absensi sedang dinonaktifkan oleh admin.' });

                return;
            }

            setStage({ name: 'unavailable', message: 'Tidak dapat menghubungi server HRIS.' });
        }
    }, []);

    useEffect(() => {
        if (!storedToken()) return;

        void boot();
    }, [boot]);

    // Wall clock, so somebody walking up can see the terminal is alive and what
    // time it thinks it is before they trust it with their check-in.
    useEffect(() => {
        const timer = setInterval(() => setNow(new Date()), 1000);

        return () => clearInterval(timer);
    }, []);

    // Keep the service-health banner honest while the terminal sits idle.
    useEffect(() => {
        if (stage.name !== 'idle') return;

        const timer = setInterval(() => {
            fetchSettings().then(setSettings).catch(() => undefined);
        }, HEALTH_POLL_MS);

        return () => clearInterval(timer);
    }, [stage.name]);

    // Auto-reset: nobody should have to dismiss a result, and a name left on
    // screen must not be confirmable by whoever walks up next.
    useEffect(() => {
        if (stage.name !== 'success' && stage.name !== 'failure') return;

        const timer = setTimeout(() => setStage({ name: 'idle' }), RESULT_MS);

        return () => clearTimeout(timer);
    }, [stage]);

    // A name left on screen must not still be confirmable by whoever walks up
    // next, so the panel expires on its own.
    useEffect(() => {
        if (stage.name !== 'confirm') return;

        const timer = setInterval(() => {
            if (Date.now() >= stage.expiresAt) {
                setStage({ name: 'idle' });
            }
        }, 500);

        return () => clearInterval(timer);
    }, [stage]);

    const handleCapture = useCallback(async (photo: Blob) => {
        if (stageRef.current.name !== 'idle') return;

        setStage({ name: 'identifying' });

        try {
            setStage({
                name: 'confirm',
                scan: await identify(photo),
                expiresAt: Date.now() + CONFIRM_SECONDS * 1000,
            });
        } catch (error) {
            setStage({
                name: 'failure',
                message:
                    error instanceof KioskApiError
                        ? error.message
                        : 'Tidak dapat menghubungi server. Coba lagi.',
            });
        }
    }, []);

    const confirmScan = useCallback(async (scan: IdentifyResult) => {
        setStage({ name: 'recording' });

        try {
            const result = await recordScan(scan.scan_id);
            setStage({ name: 'success', message: result.message });
        } catch (error) {
            setStage({
                name: 'failure',
                message:
                    error instanceof KioskApiError
                        ? error.message
                        : 'Gagal menyimpan absensi. Coba lagi.',
            });
        }
    }, []);

    if (stage.name === 'pairing') {
        return <PairingScreen error={stage.error} onPaired={boot} />;
    }

    if (stage.name === 'booting') {
        return <FullScreenNotice icon={<ScanFace size={56} />} title="Menyiapkan terminal…" />;
    }

    if (stage.name === 'unavailable') {
        return (
            <FullScreenNotice
                icon={<WifiOff size={56} className="text-amber-400" />}
                title="Terminal tidak tersedia"
                detail={stage.message}
                action={{ label: 'Coba lagi', onClick: () => void boot() }}
            />
        );
    }

    const serviceDown = settings?.face_service_operational === false;
    const scanning = stage.name === 'idle' && !serviceDown;

    return (
        <div className="relative h-full w-full bg-slate-950 text-slate-50">
            <CameraStage
                active={scanning}
                onCapture={(photo) => void handleCapture(photo)}
                onError={(message) => setStage({ name: 'unavailable', message })}
            />

            <div className="pointer-events-none absolute inset-0 flex flex-col justify-between bg-gradient-to-b from-slate-950/80 via-transparent to-slate-950/90 p-8">
                <header className="flex items-start justify-between">
                    <div>
                        <p className="text-5xl font-semibold tabular-nums">
                            {now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}
                        </p>
                        <p className="mt-1 text-sm text-slate-300">
                            {now.toLocaleDateString('id-ID', {
                                weekday: 'long',
                                day: 'numeric',
                                month: 'long',
                                year: 'numeric',
                            })}
                        </p>
                    </div>
                    <div className="text-right text-sm text-slate-400">
                        <p className="font-medium text-slate-200">{settings?.device.name}</p>
                        {settings?.device.location && <p>{settings.device.location}</p>}
                    </div>
                </header>

                <div className="pointer-events-auto flex flex-col items-center">
                    {serviceDown && (
                        <Banner tone="warning">
                            Layanan pengenalan wajah sedang tidak tersedia. Hubungi admin.
                        </Banner>
                    )}

                    {stage.name === 'idle' && !serviceDown && (
                        <Banner tone="neutral">Hadapkan wajah Anda ke kamera untuk absen</Banner>
                    )}

                    {stage.name === 'identifying' && <Banner tone="neutral">Mengenali wajah…</Banner>}

                    {stage.name === 'recording' && <Banner tone="neutral">Menyimpan absensi…</Banner>}

                    {stage.name === 'confirm' && (
                        <ConfirmPanel
                            scan={stage.scan}
                            countdown={Math.max(
                                0,
                                Math.ceil((stage.expiresAt - now.getTime()) / 1000),
                            )}
                            onConfirm={() => void confirmScan(stage.scan)}
                            onReject={() => setStage({ name: 'idle' })}
                        />
                    )}

                    {stage.name === 'success' && (
                        <ResultPanel
                            tone="success"
                            icon={<CheckCircle2 size={44} />}
                            message={stage.message}
                        />
                    )}

                    {stage.name === 'failure' && (
                        <ResultPanel
                            tone="failure"
                            icon={<TriangleAlert size={44} />}
                            message={stage.message}
                        />
                    )}
                </div>
            </div>
        </div>
    );
}

function ConfirmPanel({
    scan,
    countdown,
    onConfirm,
    onReject,
}: {
    scan: IdentifyResult;
    countdown: number;
    onConfirm: () => void;
    onReject: () => void;
}) {
    return (
        <div className="w-full max-w-2xl rounded-3xl bg-slate-900/95 p-8 text-center shadow-2xl ring-1 ring-white/10">
            <p className="text-sm tracking-widest text-slate-400 uppercase">Terdeteksi sebagai</p>
            <p className="mt-2 text-4xl font-semibold">{scan.employee.name}</p>
            <p className="mt-1 text-sm text-slate-400">
                {scan.employee.employee_number}
                {scan.employee.department ? ` · ${scan.employee.department}` : ''}
            </p>

            <p className="mt-6 text-2xl font-medium text-emerald-300">{scan.prompt}</p>

            <div className="mt-8 grid grid-cols-2 gap-4">
                <button
                    type="button"
                    onClick={onReject}
                    className="rounded-2xl bg-slate-800 px-6 py-5 text-lg font-medium text-slate-200 transition-colors active:bg-slate-700"
                >
                    Bukan saya
                </button>
                <button
                    type="button"
                    onClick={onConfirm}
                    className="rounded-2xl bg-emerald-500 px-6 py-5 text-lg font-semibold text-emerald-950 transition-colors active:bg-emerald-400"
                >
                    Ya, benar
                </button>
            </div>

            <p className="mt-4 text-xs text-slate-500">Batal otomatis dalam {countdown} detik</p>
        </div>
    );
}

function ResultPanel({
    tone,
    icon,
    message,
}: {
    tone: 'success' | 'failure';
    icon: ReactNode;
    message: string;
}) {
    const palette =
        tone === 'success'
            ? 'bg-emerald-500/15 text-emerald-200 ring-emerald-400/40'
            : 'bg-rose-500/15 text-rose-200 ring-rose-400/40';

    return (
        <div className={`flex w-full max-w-2xl items-center gap-5 rounded-3xl p-8 ring-1 ${palette}`}>
            {icon}
            <p className="text-left text-2xl leading-snug font-medium">{message}</p>
        </div>
    );
}

function Banner({ tone, children }: { tone: 'neutral' | 'warning'; children: ReactNode }) {
    const palette =
        tone === 'warning'
            ? 'bg-amber-500/15 text-amber-100 ring-amber-400/40'
            : 'bg-slate-900/80 text-slate-100 ring-white/10';

    return (
        <div className={`rounded-full px-8 py-4 text-lg font-medium ring-1 ${palette}`}>{children}</div>
    );
}

function FullScreenNotice({
    icon,
    title,
    detail,
    action,
}: {
    icon: ReactNode;
    title: string;
    detail?: string;
    action?: { label: string; onClick: () => void };
}) {
    return (
        <div className="flex h-full w-full flex-col items-center justify-center gap-4 bg-slate-950 p-10 text-center text-slate-200">
            {icon}
            <h1 className="text-2xl font-semibold">{title}</h1>
            {detail && <p className="max-w-md text-slate-400">{detail}</p>}
            {action && (
                <button
                    type="button"
                    onClick={action.onClick}
                    className="mt-2 rounded-xl bg-slate-800 px-6 py-3 font-medium active:bg-slate-700"
                >
                    {action.label}
                </button>
            )}
        </div>
    );
}

/**
 * One-time pairing. The token is typed in once by whoever installs the terminal
 * and then lives in localStorage — never in the URL, where it would be visible
 * on screen and recorded in the server's access log.
 */
function PairingScreen({ error, onPaired }: { error: string | null; onPaired: () => void }) {
    const [token, setToken] = useState('');

    return (
        <div className="flex h-full w-full items-center justify-center bg-slate-950 p-10">
            <form
                className="w-full max-w-md space-y-5 text-slate-200"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (token.trim() === '') return;
                    storeToken(token);
                    setToken('');
                    onPaired();
                }}
            >
                <div className="flex items-center gap-3">
                    <KeyRound size={28} className="text-emerald-400" />
                    <h1 className="text-2xl font-semibold">Pasangkan terminal</h1>
                </div>

                <p className="text-sm text-slate-400">
                    Masukkan token yang dihasilkan admin dengan <code>php artisan kiosk:register</code>.
                </p>

                {error && (
                    <p className="rounded-xl bg-rose-500/15 px-4 py-3 text-sm text-rose-200 ring-1 ring-rose-400/40">
                        {error}
                    </p>
                )}

                <input
                    type="password"
                    value={token}
                    onChange={(event) => setToken(event.target.value)}
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="Token terminal"
                    className="w-full rounded-xl bg-slate-900 px-4 py-3 font-mono ring-1 ring-white/10 outline-none focus:ring-emerald-400/60"
                />

                <button
                    type="submit"
                    className="w-full rounded-xl bg-emerald-500 px-4 py-3 font-semibold text-emerald-950 active:bg-emerald-400"
                >
                    Simpan &amp; mulai
                </button>

                <button
                    type="button"
                    onClick={() => {
                        clearToken();
                        setToken('');
                    }}
                    className="w-full text-xs text-slate-500 underline-offset-4 hover:underline"
                >
                    Hapus token tersimpan
                </button>
            </form>
        </div>
    );
}

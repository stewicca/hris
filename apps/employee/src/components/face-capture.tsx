import { Button } from '@/components/ui/button';
import {
    captureJpeg,
    createFaceLandmarker,
    detect,
    newPassiveLivenessState,
    samplePassiveLiveness,
} from '@hris/shared';
import { Loader2, ScanFace, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface FaceCaptureProps {
    open: boolean;
    onClose: () => void;
    /** Called once a live frame is captured and verified. */
    onCapture: (blob: Blob) => Promise<void> | void;
}

type Phase = 'loading_model' | 'starting_camera' | 'liveness' | 'submitting' | 'done';

/**
 * Passive-liveness overlay for the employee attendance flow (Face ID style).
 *
 * The user sees a live camera preview with a face-guide oval and a progress
 * percentage. While the face is in frame, MediaPipe runs at ~30fps and we
 * accumulate ~1s of natural-motion signal (blink and/or head drift). A held
 * photo produces no motion → rejected. Once verified, a single sharp frame is
 * captured and handed back for upload. No explicit "do X" prompts — the flow
 * stays under ~2 seconds.
 */
export function FaceCapture({ open, onClose, onCapture }: FaceCaptureProps) {
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const rafRef = useRef<number | null>(null);
    const capturedRef = useRef(false);
    const [phase, setPhase] = useState<Phase>('loading_model');
    const [error, setError] = useState<string | null>(null);
    const [progress, setProgress] = useState(0);
    const [hint, setHint] = useState('Menyiapkan kamera...');

    useEffect(() => {
        if (!open) return;

        setError(null);
        setProgress(0);
        setPhase('loading_model');
        setHint('Memuat model wajah...');
        capturedRef.current = false;

        let cancelled = false;
        let landmarker: Awaited<ReturnType<typeof createFaceLandmarker>>;

        (async () => {
            try {
                landmarker = await createFaceLandmarker();
                if (cancelled) return;

                const stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'user' },
                    audio: false,
                });
                if (cancelled) {
                    stream.getTracks().forEach((t) => t.stop());
                    return;
                }
                streamRef.current = stream;
                setPhase('starting_camera');

                const video = videoRef.current;
                if (!video) return;
                video.srcObject = stream;
                await video.play();

                const passive = newPassiveLivenessState();
                setPhase('liveness');
                setHint('Posisikan wajah di dalam lingkaran');

                const loop = () => {
                    if (cancelled || video.readyState < 2) {
                        rafRef.current = requestAnimationFrame(loop);
                        return;
                    }
                    const frame = detect(landmarker, video, performance.now());

                    if (!frame.detected) {
                        setHint('Posisikan wajah di dalam lingkaran');
                    } else {
                        const ready = samplePassiveLiveness(passive, frame, 30);
                        setProgress(
                            Math.min(100, Math.round((passive.samples / 30) * 100)),
                        );
                        if (passive.blinked || passive.samples > 8) {
                            setHint('Tahan sebentar...');
                        } else {
                            setHint('Gerakkan kepala sedikit / berkedip');
                        }
                        if (ready && !capturedRef.current) {
                            complete(blobVideo(video));
                            return;
                        }
                    }
                    rafRef.current = requestAnimationFrame(loop);
                };
                rafRef.current = requestAnimationFrame(loop);
            } catch (e) {
                if (cancelled) return;
                setError(
                    e instanceof Error && e.name === 'NotAllowedError'
                        ? 'Izin kamera ditolak. Aktifkan izin kamera di pengaturan browser.'
                        : 'Tidak dapat memulai kamera. Coba lagi.',
                );
                setPhase('loading_model');
            }
        })();

        // Capture a frame without awaiting inside the rAF loop.
        async function blobVideo(video: HTMLVideoElement) {
            return captureJpeg(video);
        }

        async function complete(blobPromise: Promise<Blob | null>) {
            if (capturedRef.current) return;
            capturedRef.current = true;
            setPhase('submitting');
            const blob = await blobPromise;
            if (!blob) {
                setError('Gagal menangkap foto. Coba lagi.');
                setPhase('liveness');
                capturedRef.current = false;
                return;
            }
            setPhase('done');
            try {
                await onCapture(blob);
            } catch (e) {
                const msg =
                    e instanceof Error
                        ? e.message
                        : typeof e === 'string'
                          ? e
                          : 'Verifikasi gagal di server. Coba lagi.';
                setError(msg);
                setPhase('liveness');
                capturedRef.current = false;
            }
        }

        return () => {
            cancelled = true;
            if (rafRef.current) cancelAnimationFrame(rafRef.current);
            streamRef.current?.getTracks().forEach((t) => t.stop());
            streamRef.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4">
            <div className="w-full max-w-sm space-y-4 rounded-2xl bg-card p-5 shadow-2xl">
                <div className="flex items-center justify-between">
                    <h3 className="flex items-center gap-2 font-semibold text-foreground">
                        <ScanFace size={18} className="text-primary" />
                        Verifikasi Wajah
                    </h3>
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={onClose}
                        disabled={phase === 'submitting' || phase === 'done'}
                    >
                        <X size={16} />
                    </Button>
                </div>

                <div className="relative aspect-square w-full overflow-hidden rounded-xl bg-black">
                    <video
                        ref={videoRef}
                        playsInline
                        muted
                        className="h-full w-full -scale-x-100 object-cover"
                    />
                    <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                        <div className="h-3/4 w-3/5 rounded-full border-2 border-white/70" />
                    </div>

                    {(phase === 'loading_model' ||
                        phase === 'starting_camera') && (
                        <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 text-white">
                            <Loader2 size={28} className="animate-spin" />
                            <span className="text-xs">
                                {phase === 'loading_model'
                                    ? 'Memuat model wajah...'
                                    : 'Menyalakan kamera...'}
                            </span>
                        </div>
                    )}

                    {phase === 'liveness' && (
                        <div className="absolute bottom-3 left-1/2 h-1 w-2/3 -translate-x-1/2 overflow-hidden rounded-full bg-white/20">
                            <div
                                className="h-full bg-primary transition-all"
                                style={{ width: `${progress}%` }}
                            />
                        </div>
                    )}
                </div>

                <div className="min-h-10 text-center">
                    {error ? (
                        <p className="text-xs text-destructive">{error}</p>
                    ) : phase === 'submitting' || phase === 'done' ? (
                        <p className="flex items-center justify-center gap-2 text-xs font-medium text-primary">
                            <Loader2 size={14} className="animate-spin" />
                            Mengirim verifikasi...
                        </p>
                    ) : (
                        <p className="text-xs font-medium text-foreground">
                            {hint}
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}

import { Loader2, ScanFace, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    CHALLENGE_PROMPTS,
    captureJpeg,
    createFaceLandmarker,
    detect,
    randomChallenges,
    samplePassiveLiveness,
    newPassiveLivenessState,
    verifyChallenge,
} from '@hris/shared';
import type {
    ChallengeState,
    FaceFrame,
    LivenessChallenge,
} from '@hris/shared';

type Mode = 'challenges' | 'passive';

interface FaceCaptureDialogProps {
    open: boolean;
    onClose: () => void;
    onCapture: (blob: Blob) => Promise<void> | void;
    /** Number of random challenge prompts to require (enrollment: 3). */
    challengeCount?: number;
    /** When true, run passive liveness instead of explicit challenges. */
    mode?: Mode;
    submitLabel?: string;
}

type Phase =
    | 'loading_model'
    | 'starting_camera'
    | 'liveness'
    | 'capturing'
    | 'done';

/**
 * Camera + liveness overlay.
 *
 * The detection loop runs in requestAnimationFrame and reads/mutates refs,
 * NOT React state, so each frame sees the latest values. React state is used
 * only to drive what the user sees (phase, current prompt, progress dots).
 */
export function FaceCaptureDialog({
    open,
    onClose,
    onCapture,
    challengeCount = 3,
    mode = 'challenges',
    submitLabel = 'Memproses...',
}: FaceCaptureDialogProps) {
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const rafRef = useRef<number | null>(null);

    // ---- rAF-loop state (mutable, no re-render) ----
    const queueRef = useRef<LivenessChallenge[]>([]);
    const challengeStateRef = useRef<ChallengeState | null>(null);
    const historyRef = useRef({ yaw: 0, pitch: 0, blinkClosed: false });
    const passiveRef = useRef(newPassiveLivenessState());
    const capturedRef = useRef(false);

    // ---- React state (display only) ----
    const [phase, setPhase] = useState<Phase>('loading_model');
    const [error, setError] = useState<string | null>(null);
    const [currentChallengeIdx, setCurrentChallengeIdx] = useState(0);
    const [currentPrompt, setCurrentPrompt] = useState<string>('');
    const [queueView, setQueueView] = useState<LivenessChallenge[]>([]);
    const [progress, setProgress] = useState(0);

    useEffect(() => {
        if (!open) return;

        // reset refs + display state
        setError(null);
        setPhase('loading_model');
        setCurrentPrompt('');
        setProgress(0);
        setCurrentChallengeIdx(0);
        historyRef.current = { yaw: 0, pitch: 0, blinkClosed: false };
        passiveRef.current = newPassiveLivenessState();
        capturedRef.current = false;
        challengeStateRef.current = null;

        let landmarker: Awaited<ReturnType<typeof createFaceLandmarker>>;
        let cancelled = false;

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

                if (mode === 'challenges') {
                    const order = randomChallenges(challengeCount);
                    queueRef.current = order;
                    setQueueView(order);
                    challengeStateRef.current = {
                        challenge: order[0],
                        status: 'incomplete',
                    };
                    setCurrentPrompt(CHALLENGE_PROMPTS[order[0]]);
                } else {
                    setCurrentPrompt('Posisikan wajah ke kamera...');
                }
                setPhase('liveness');

                const loop = () => {
                    if (cancelled) return;
                    if (video.readyState < 2) {
                        rafRef.current = requestAnimationFrame(loop);
                        return;
                    }
                    const frame = detect(landmarker, video, performance.now());

                    if (mode === 'challenges') {
                        runChallengeFrame(frame);
                    } else {
                        runPassiveFrame(frame);
                    }
                    rafRef.current = requestAnimationFrame(loop);
                };
                rafRef.current = requestAnimationFrame(loop);
            } catch (e) {
                if (cancelled) return;
                setError(
                    e instanceof Error && e.name === 'NotAllowedError'
                        ? 'Izin kamera ditolak. Aktifkan izin kamera di browser.'
                        : 'Tidak dapat memulai kamera. Coba lagi.',
                );
                setPhase('loading_model');
            }
        })();

        return () => {
            cancelled = true;
            if (rafRef.current) cancelAnimationFrame(rafRef.current);
            streamRef.current?.getTracks().forEach((t) => t.stop());
            streamRef.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    function runChallengeFrame(frame: FaceFrame) {
        const state = challengeStateRef.current;
        if (!state || state.status === 'complete') return;

        if (!frame.detected) {
            setCurrentPrompt('Posisikan wajah di dalam lingkaran');
            return;
        }
        setCurrentPrompt(CHALLENGE_PROMPTS[state.challenge]);

        const { done } = verifyChallenge(state, frame, historyRef.current);
        if (done) {
            state.status = 'complete';
            const nextIdx = currentChallengeIdx + 1;
            // use the ref queue, not React state, for ground truth
            const queue = queueRef.current;
            const realNextIdx =
                queue.findIndex((c) => c === state.challenge) + 1;
            const advanceTo = Math.max(nextIdx, realNextIdx);
            if (advanceTo >= queue.length) {
                void complete();
            } else {
                historyRef.current = {
                    yaw: 0,
                    pitch: 0,
                    blinkClosed: false,
                };
                challengeStateRef.current = {
                    challenge: queue[advanceTo],
                    status: 'incomplete',
                };
                setCurrentChallengeIdx(advanceTo);
                setCurrentPrompt(CHALLENGE_PROMPTS[queue[advanceTo]]);
            }
        }
    }

    function runPassiveFrame(frame: FaceFrame) {
        if (!frame.detected) {
            setCurrentPrompt('Posisikan wajah di dalam lingkaran');
            return;
        }
        const ready = samplePassiveLiveness(passiveRef.current, frame, 30);
        const pct = Math.min(
            100,
            Math.round((passiveRef.current.samples / 30) * 100),
        );
        setProgress(pct);
        setCurrentPrompt(
            passiveRef.current.blinked || passiveRef.current.samples > 8
                ? 'Tahan sebentar...'
                : 'Gerakkan kepala sedikit / berkedip',
        );
        if (ready && !capturedRef.current) {
            void complete();
        }
    }

    async function complete() {
        if (capturedRef.current) return;
        capturedRef.current = true;
        setPhase('capturing');
        const blob = await captureJpeg(videoRef.current!);
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
                      : 'Gagal menyimpan. Coba lagi.';
            setError(msg);
            setPhase('liveness');
            capturedRef.current = false;
        }
    }

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
            <div className="w-full max-w-md space-y-4 rounded-2xl bg-card p-5 shadow-2xl">
                <div className="flex items-center justify-between">
                    <h3 className="flex items-center gap-2 font-semibold">
                        <ScanFace className="size-5 text-primary" />
                        {mode === 'challenges'
                            ? 'Pendaftaran Wajah'
                            : 'Verifikasi Wajah'}
                    </h3>
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={onClose}
                        disabled={phase === 'done' || phase === 'capturing'}
                    >
                        <X className="size-4" />
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
                        <div className="h-3/4 w-3/5 rounded-full border-2 border-white/60" />
                    </div>

                    {(phase === 'loading_model' ||
                        phase === 'starting_camera') && (
                        <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 text-white">
                            <Loader2 className="size-8 animate-spin" />
                            <span className="text-sm">
                                {phase === 'loading_model'
                                    ? 'Memuat model wajah...'
                                    : 'Menyalakan kamera...'}
                            </span>
                        </div>
                    )}

                    {phase === 'liveness' && mode === 'passive' && (
                        <div className="absolute bottom-3 left-1/2 h-1 w-2/3 -translate-x-1/2 overflow-hidden rounded-full bg-white/20">
                            <div
                                className="h-full bg-primary transition-all"
                                style={{ width: `${progress}%` }}
                            />
                        </div>
                    )}
                </div>

                <div className="min-h-12 space-y-2 text-center">
                    {error ? (
                        <p className="text-sm text-destructive">{error}</p>
                    ) : phase === 'capturing' || phase === 'done' ? (
                        <p className="flex items-center justify-center gap-2 text-sm text-primary">
                            <Loader2 className="size-4 animate-spin" />
                            {submitLabel}
                        </p>
                    ) : (
                        <>
                            <p className="text-sm font-medium text-foreground">
                                {currentPrompt || 'Menyiapkan...'}
                            </p>
                            {mode === 'challenges' && queueView.length > 0 && (
                                <div className="flex items-center justify-center gap-1.5">
                                    {queueView.map((c, i) => (
                                        <span
                                            key={c}
                                            className={`h-1.5 w-8 rounded-full ${
                                                i < currentChallengeIdx
                                                    ? 'bg-primary'
                                                    : i === currentChallengeIdx
                                                      ? 'bg-primary/60'
                                                      : 'bg-muted'
                                            }`}
                                        />
                                    ))}
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

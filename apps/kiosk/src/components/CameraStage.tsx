import {
    captureJpeg,
    createFaceLandmarker,
    detect,
    newPassiveLivenessState,
    samplePassiveLiveness,
} from '@hris/shared';
import { Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface CameraStageProps {
    /** While false the preview keeps running but nothing is captured. */
    active: boolean;
    onCapture: (photo: Blob) => void;
    onError: (message: string) => void;
}

/** Frames of natural motion required before a face is considered live. */
const LIVENESS_SAMPLES = 30;

/**
 * The camera half of the terminal.
 *
 * The stream is opened once and left running for the life of the page: a kiosk
 * serves a queue, and re-negotiating the camera between people would put a
 * visible stall in front of every single one of them. Only the capture gate
 * opens and closes, which is what `active` controls.
 *
 * Liveness is the passive check from @hris/shared — roughly a second of blink
 * or head drift. A photograph held up to the lens produces neither. It is a
 * deterrent rather than a proof; the server runs its own check on the frame
 * that gets sent.
 */
export function CameraStage({ active, onCapture, onError }: CameraStageProps) {
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const activeRef = useRef(active);
    const busyRef = useRef(false);
    const [ready, setReady] = useState(false);
    const [progress, setProgress] = useState(0);
    const [faceInFrame, setFaceInFrame] = useState(false);

    useEffect(() => {
        activeRef.current = active;
        busyRef.current = false;
    }, [active]);

    useEffect(() => {
        let cancelled = false;
        let raf: number | null = null;
        let stream: MediaStream | null = null;

        (async () => {
            try {
                const landmarker = await createFaceLandmarker();
                if (cancelled) return;

                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'user', width: 1280, height: 720 },
                    audio: false,
                });
                if (cancelled) {
                    stream.getTracks().forEach((track) => track.stop());
                    return;
                }

                const video = videoRef.current;
                if (!video) return;
                video.srcObject = stream;
                await video.play();
                setReady(true);

                let passive = newPassiveLivenessState();

                const loop = () => {
                    raf = requestAnimationFrame(loop);

                    if (cancelled || video.readyState < 2) return;

                    if (!activeRef.current || busyRef.current) {
                        passive = newPassiveLivenessState();
                        // Functional updates so an already-clear overlay is not
                        // re-rendered on every one of the ~60 frames a second
                        // that pass while the terminal is showing a result.
                        setProgress((current) => (current === 0 ? current : 0));
                        setFaceInFrame((current) => (current ? false : current));

                        return;
                    }

                    const frame = detect(landmarker, video, performance.now());
                    setFaceInFrame(frame.detected);

                    if (!frame.detected) {
                        passive = newPassiveLivenessState();
                        setProgress(0);
                        return;
                    }

                    const live = samplePassiveLiveness(passive, frame, LIVENESS_SAMPLES);
                    setProgress(Math.min(100, Math.round((passive.samples / LIVENESS_SAMPLES) * 100)));

                    if (!live) return;

                    busyRef.current = true;
                    passive = newPassiveLivenessState();

                    captureJpeg(video)
                        .then((photo) => {
                            if (cancelled) return;
                            if (!photo) {
                                busyRef.current = false;
                                return;
                            }
                            onCapture(photo);
                        })
                        .catch(() => {
                            busyRef.current = false;
                        });
                };

                raf = requestAnimationFrame(loop);
            } catch (error) {
                if (cancelled) return;
                onError(
                    error instanceof Error && error.name === 'NotAllowedError'
                        ? 'Izin kamera ditolak. Buka pengaturan browser terminal dan izinkan kamera.'
                        : 'Kamera tidak dapat dinyalakan. Periksa perangkat kamera terminal.',
                );
            }
        })();

        return () => {
            cancelled = true;
            if (raf) cancelAnimationFrame(raf);
            stream?.getTracks().forEach((track) => track.stop());
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div className="relative h-full w-full overflow-hidden bg-black">
            <video
                ref={videoRef}
                playsInline
                muted
                className="h-full w-full -scale-x-100 object-cover"
            />

            {!ready && (
                <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 text-slate-300">
                    <Loader2 size={36} className="animate-spin" />
                    <span className="text-sm tracking-wide uppercase">Menyalakan kamera…</span>
                </div>
            )}

            {/* Face guide. Green once a face is being tracked, so someone can
                tell at a glance whether to step closer. */}
            <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                <div
                    className={`h-[62%] w-[42%] rounded-[50%] border-4 transition-colors duration-300 ${
                        active && faceInFrame ? 'border-emerald-400/80' : 'border-white/25'
                    }`}
                />
            </div>

            {active && progress > 0 && (
                <div className="pointer-events-none absolute right-0 bottom-0 left-0 h-1.5 bg-white/10">
                    <div
                        className="h-full bg-emerald-400 transition-all duration-150"
                        style={{ width: `${progress}%` }}
                    />
                </div>
            )}
        </div>
    );
}

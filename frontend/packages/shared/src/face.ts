/**
 * Face mesh utilities for liveness detection, shared by the back-office
 * enrollment flow and the employee PWA attendance flow.
 *
 * Uses MediaPipe Tasks Vision (FaceLandmarker, 478 landmarks) loaded once and
 * reused. WASM + model weights come from the jsdelivr CDN by default — for
 * fully air-gapped deployments these can be vendored locally (see TODO in
 * createFaceLandmarker).
 */

import {
    FaceLandmarker,
    FilesetResolver
    
} from '@mediapipe/tasks-vision';
import type {FaceLandmarkerResult} from '@mediapipe/tasks-vision';

export type LivenessChallenge =
    | 'blink'
    | 'look_left'
    | 'look_right'
    | 'smile'
    | 'nod';

export const CHALLENGE_PROMPTS: Record<LivenessChallenge, string> = {
    blink: 'Berkedip',
    look_left: 'Tengok ke kiri',
    look_right: 'Tengok ke kanan',
    smile: 'Senyum',
    nod: 'Anggukkan kepala',
};

let landmarkerSingleton: FaceLandmarker | null = null;
let loadingPromise: Promise<FaceLandmarker> | null = null;

/**
 * Lazily create the FaceLandmarker. The WASM runtime and ~4MB model are
 * fetched once, cached by the browser, and shared across every capture.
 */
export async function createFaceLandmarker(): Promise<FaceLandmarker> {
    if (landmarkerSingleton) return landmarkerSingleton;
    if (loadingPromise) return loadingPromise;

    loadingPromise = (async () => {
        const fileset = await FilesetResolver.forVisionTasks(
            'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.35/wasm',
        );
        const landmarker = await FaceLandmarker.createFromOptions(fileset, {
            baseOptions: {
                modelAssetPath:
                    'https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task',
                delegate: 'GPU',
            },
            runningMode: 'VIDEO',
            numFaces: 1,
            outputFaceBlendshapes: true,
        });
        landmarkerSingleton = landmarker;
        return landmarker;
    })();

    return loadingPromise;
}

export interface FaceFrame {
    /** raw MediaPipe result for the latest processed frame */
    result: FaceLandmarkerResult;
    /** true when exactly one face is present in the frame */
    detected: boolean;
    /** ARKit-style expression coefficients, when available */
    blendshapes: Record<string, number>;
    /** estimated head pose in degrees, derived from landmarks */
    yaw: number;
    pitch: number;
}

const LANDMARK = {
    noseTip: 1,
    foreheadCenter: 10,
    chin: 152,
    leftCheek: 234, // subject's left (right side of image)
    rightCheek: 454, // subject's right (left side of image)
} as const;

/**
 * Run the landmarker on one video frame and derive metrics used by the
 * liveness verifiers below.
 */
export function detect(
    landmarker: FaceLandmarker,
    video: HTMLVideoElement,
    timestamp: number,
): FaceFrame {
    const result = landmarker.detectForVideo(video, timestamp);

    if (result.faceLandmarks.length === 0) {
        return {
            result,
            detected: false,
            blendshapes: {},
            yaw: 0,
            pitch: 0,
        };
    }

    const lm = result.faceLandmarks[0];
    const nose = lm[LANDMARK.noseTip];
    const leftCheek = lm[LANDMARK.leftCheek];
    const rightCheek = lm[LANDMARK.rightCheek];
    const forehead = lm[LANDMARK.foreheadCenter];
    const chin = lm[LANDMARK.chin];

    // Yaw: ratio of nose→cheek distances. Looking right (>0) shrinks the
    // right cheek gap relative to the left.
    const distL = Math.hypot(nose.x - leftCheek.x, nose.y - leftCheek.y);
    const distR = Math.hypot(nose.x - rightCheek.x, nose.y - rightCheek.y);
    const yaw = ((distR - distL) / (distR + distL)) * 90;

    // Pitch: ratio of forehead→nose vs nose→chin. Nodding down (>0) lengthens
    // the upper segment.
    const upper = Math.hypot(forehead.x - nose.x, forehead.y - nose.y);
    const lower = Math.hypot(nose.x - chin.x, nose.y - chin.y);
    const pitch = ((upper - lower) / (upper + lower)) * 90;

    const blendshapes: Record<string, number> = {};
    if (result.faceBlendshapes?.[0]?.categories) {
        for (const c of result.faceBlendshapes[0].categories) {
            blendshapes[c.categoryName] = c.score;
        }
    }

    return { result, detected: true, blendshapes, yaw, pitch };
}

export interface ChallengeState {
    challenge: LivenessChallenge;
    /** 'incomplete' until the gesture is detected, then 'complete'. */
    status: 'incomplete' | 'complete';
}

/**
 * Inspect one frame and update the challenge state machine. Returns a
 * human-readable hint for the current phase.
 *
 * Each verifier tracks a tiny bit of history (closure / excursion) so a single
 * noisy frame can't complete a challenge. Thresholds are deliberately lenient
 * — the goal is to defeat static-photo spoofing, not to be a strict proctor.
 */
export function verifyChallenge(
    state: ChallengeState,
    frame: FaceFrame,
    history: { yaw: number; pitch: number; blinkClosed: boolean },
): { done: boolean } {
    if (!frame.detected) return { done: false };

    switch (state.challenge) {
        case 'blink': {
            // A blink is: eyes go closed, then open again. Track the closed
            // frame explicitly so a half-blink doesn't falsely satisfy it.
            const closed =
                (frame.blendshapes.eyeBlinkLeft ?? 0) > 0.5 &&
                (frame.blendshapes.eyeBlinkRight ?? 0) > 0.5;
            if (closed) history.blinkClosed = true;
            if (history.blinkClosed && !closed) {
                state.status = 'complete';
                return { done: true };
            }
            return { done: false };
        }
        case 'look_left': {
            // Subject turns to their left — nose moves toward right cheek.
            if (frame.yaw < -18) {
                state.status = 'complete';
                return { done: true };
            }
            return { done: false };
        }
        case 'look_right': {
            if (frame.yaw > 18) {
                state.status = 'complete';
                return { done: true };
            }
            return { done: false };
        }
        case 'smile': {
            const smiling =
                (frame.blendshapes.mouthSmileLeft ?? 0) > 0.35 &&
                (frame.blendshapes.mouthSmileRight ?? 0) > 0.35;
            if (smiling) {
                state.status = 'complete';
                return { done: true };
            }
            return { done: false };
        }
        case 'nod': {
            // A nod is: pitch dips down then returns. Track the low point.
            if (frame.pitch > 8) history.pitch = Math.max(history.pitch, frame.pitch);
            if (history.pitch > 8 && frame.pitch < 2) {
                state.status = 'complete';
                return { done: true };
            }
            return { done: false };
        }
    }
}

/**
 * Pick N distinct random challenges from the pool. Enrollment uses several;
 * the PWA flow uses fewer (and may use passive liveness instead).
 */
export function randomChallenges(count: number): LivenessChallenge[] {
    const pool: LivenessChallenge[] = [
        'blink',
        'look_left',
        'look_right',
        'smile',
        'nod',
    ];
    // Fisher-Yates shuffle, take the first `count`.
    for (let i = pool.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [pool[i], pool[j]] = [pool[j], pool[i]];
    }
    return pool.slice(0, Math.min(count, pool.length));
}

export interface PassiveLivenessState {
    /** samples collected so far */
    samples: number;
    /** whether a blink has been observed */
    blinked: boolean;
    /** peak-to-peak yaw excursion in degrees */
    yawRange: number;
    /** peak-to-peak pitch excursion in degrees */
    pitchRange: number;
    minYaw: number;
    maxYaw: number;
    minPitch: number;
    maxPitch: number;
    lastBlinkClosed: boolean;
}

export function newPassiveLivenessState(): PassiveLivenessState {
    return {
        samples: 0,
        blinked: false,
        yawRange: 0,
        pitchRange: 0,
        minYaw: 0,
        maxYaw: 0,
        minPitch: 0,
        maxPitch: 0,
        lastBlinkClosed: false,
    };
}

/**
 * Feed one frame to the passive-liveness accumulator. A photo (held still)
 * produces negligible motion and no blinks; a live face naturally drifts and
 * blinks within ~1s. Returns true once enough liveness signal has gathered.
 *
 * `requiredSamples` frames must be gathered; pass completes when either a
 * blink is seen OR combined yaw+pitch motion exceeds a threshold.
 */
export function samplePassiveLiveness(
    state: PassiveLivenessState,
    frame: FaceFrame,
    requiredSamples: number,
): boolean {
    if (!frame.detected) return false;

    state.samples += 1;

    // Blink edge detection.
    const closed =
        (frame.blendshapes.eyeBlinkLeft ?? 0) > 0.45 &&
        (frame.blendshapes.eyeBlinkRight ?? 0) > 0.45;
    if (state.lastBlinkClosed && !closed) state.blinked = true;
    state.lastBlinkClosed = closed;

    if (state.samples === 1) {
        state.minYaw = state.maxYaw = frame.yaw;
        state.minPitch = state.maxPitch = frame.pitch;
    } else {
        state.minYaw = Math.min(state.minYaw, frame.yaw);
        state.maxYaw = Math.max(state.maxYaw, frame.yaw);
        state.minPitch = Math.min(state.minPitch, frame.pitch);
        state.maxPitch = Math.max(state.maxPitch, frame.pitch);
    }
    state.yawRange = state.maxYaw - state.minYaw;
    state.pitchRange = state.maxPitch - state.minPitch;

    if (state.samples < requiredSamples) return false;

    // Pass: a blink is strong evidence; otherwise require noticeable motion.
    if (state.blinked) return true;
    return state.yawRange + state.pitchRange > 14;
}

/**
 * Capture the current video frame as a JPEG Blob, suitable for upload.
 */
export function captureJpeg(
    video: HTMLVideoElement,
    quality = 0.9,
): Promise<Blob | null> {
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth || 480;
    canvas.height = video.videoHeight || 640;
    const ctx = canvas.getContext('2d');
    if (!ctx) return Promise.resolve(null);
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    return new Promise((resolve) => canvas.toBlob((b) => resolve(b), 'image/jpeg', quality));
}

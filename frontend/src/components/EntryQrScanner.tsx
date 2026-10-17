import { useEffect, useRef, useState } from 'react';
import type { IScannerControls } from '@zxing/browser';
import { Alert } from './ui';

interface Props {
    disabled?: boolean;
    onDecoded: (reference: string) => Promise<void> | void;
}

type CameraState = 'idle' | 'starting' | 'scanning' | 'error' | 'unsupported';

function cameraErrorMessage(error: unknown): string {
    const name = (error as { name?: string })?.name;

    if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
        return 'Camera permission was denied. Allow camera access in your browser settings, or enter the code manually.';
    }

    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
        return 'No usable camera was found. Enter the code manually or scan a QR image instead.';
    }

    if (name === 'NotReadableError' || name === 'TrackStartError') {
        return 'The camera could not be started because it is busy or unavailable. Enter the code manually instead.';
    }

    if (name === 'OverconstrainedError') {
        return 'The available camera does not meet the required constraints. Enter the code manually instead.';
    }

    return 'The camera could not be started. Enter the code manually or scan a QR image instead.';
}

export function EntryQrScanner({ disabled = false, onDecoded }: Props) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const imageInputRef = useRef<HTMLInputElement>(null);
    const controlsRef = useRef<IScannerControls | null>(null);
    const decodedRef = useRef(false);
    const mountedRef = useRef(true);
    const onDecodedRef = useRef(onDecoded);

    const [cameraState, setCameraState] = useState<CameraState>('idle');
    const [cameraError, setCameraError] = useState<string | null>(null);
    const [imageBusy, setImageBusy] = useState(false);
    const [imageError, setImageError] = useState<string | null>(null);

    onDecodedRef.current = onDecoded;

    const stopTracks = () => {
        const video = videoRef.current;
        const stream = video?.srcObject;

        if (stream instanceof MediaStream) {
            stream.getTracks().forEach((track) => track.stop());
        }

        if (video) video.srcObject = null;
    };

    const stopCamera = (nextState: CameraState = 'idle') => {
        controlsRef.current?.stop();
        controlsRef.current = null;
        stopTracks();

        if (mountedRef.current) setCameraState(nextState);
    };

    useEffect(() => {
        mountedRef.current = true;

        return () => {
            mountedRef.current = false;
            controlsRef.current?.stop();
            controlsRef.current = null;
            stopTracks();
        };
    }, []);

    useEffect(() => {
        const onPageHide = () => stopCamera();
        window.addEventListener('pagehide', onPageHide);

        return () => window.removeEventListener('pagehide', onPageHide);
    }, []);

    const processReference = async (reference: string) => {
        if (reference.trim() === '' || decodedRef.current) return;

        decodedRef.current = true;
        stopCamera();
        await onDecodedRef.current(reference);
    };

    const startCamera = async () => {
        setCameraError(null);
        setImageError(null);

        if (!navigator.mediaDevices?.getUserMedia) {
            setCameraState('unsupported');
            setCameraError(
                'This browser does not expose a camera scanning API. Enter the code manually or scan a QR image instead.',
            );

            return;
        }

        setCameraState('starting');

        try {
            decodedRef.current = false;
            const { BrowserQRCodeReader } = await import('@zxing/browser');
            const reader = new BrowserQRCodeReader();
            const video = videoRef.current;

            if (!video) {
                setCameraState('idle');
                return;
            }

            const controls = await reader.decodeFromVideoDevice(undefined, video, (result) => {
                if (result && !decodedRef.current) {
                    void processReference(result.getText());
                }
            });

            if (!mountedRef.current || decodedRef.current) {
                controls.stop();
                return;
            }

            controlsRef.current = controls;
            setCameraState('scanning');
        } catch (error) {
            stopCamera('error');
            setCameraError(cameraErrorMessage(error));
        }
    };

    const scanImage = async (file: File | undefined) => {
        if (!file) return;

        setCameraError(null);
        setImageError(null);
        setImageBusy(true);
        stopCamera();
        decodedRef.current = false;

        const url = URL.createObjectURL(file);

        try {
            const { BrowserQRCodeReader } = await import('@zxing/browser');
            const result = await new BrowserQRCodeReader().decodeFromImageUrl(url);
            await processReference(result.getText());
        } catch {
            setImageError('No readable QR code was found in that image. Try another image or enter the code manually.');
        } finally {
            URL.revokeObjectURL(url);
            setImageBusy(false);
        }
    };

    return (
        <section className="entry-scanner" aria-labelledby="entry-scanner-title">
            <div className="section-head">
                <div>
                    <h3 id="entry-scanner-title">Scan entry QR</h3>
                    <p className="muted section-sub">
                        The camera is used only while you are scanning. Validation still happens on the server.
                    </p>
                </div>
            </div>

            <div className="entry-scanner-stage">
                <video
                    ref={videoRef}
                    className="entry-scanner-video"
                    aria-label="Camera preview for QR scanning"
                    autoPlay
                    muted
                    playsInline
                />
                {cameraState !== 'scanning' ? (
                    <div className="entry-scanner-placeholder">
                        <span>Camera preview</span>
                        <span className="muted small">Start the camera when you are ready to scan.</span>
                    </div>
                ) : null}
            </div>

            <div className="entry-scanner-actions">
                {cameraState === 'scanning' ? (
                    <button type="button" className="btn btn-ghost" onClick={() => stopCamera()}>
                        Stop camera
                    </button>
                ) : (
                    <button
                        type="button"
                        className="btn btn-primary"
                        onClick={() => void startCamera()}
                        disabled={disabled || cameraState === 'starting'}
                    >
                        {cameraState === 'starting' ? 'Starting camera…' : 'Start camera'}
                    </button>
                )}

                <button
                    type="button"
                    className="btn btn-ghost"
                    onClick={() => imageInputRef.current?.click()}
                    disabled={disabled || imageBusy}
                >
                    {imageBusy ? 'Reading image…' : 'Scan QR image'}
                </button>
                <input
                    ref={imageInputRef}
                    id="entry-qr-image"
                    className="visually-hidden"
                    type="file"
                    accept="image/*"
                    aria-label="Scan a QR image file"
                    disabled={disabled || imageBusy}
                    onChange={(event) => {
                        const file = event.target.files?.[0];
                        void scanImage(file);
                        event.currentTarget.value = '';
                    }}
                />
            </div>

            <p className="muted small entry-scanner-help">
                Camera permission is requested only after you choose Start camera. If the camera is unavailable, use
                manual entry or scan a saved QR image.
            </p>

            {cameraError ? <Alert kind="error">{cameraError}</Alert> : null}
            {imageError ? <Alert kind="error">{imageError}</Alert> : null}
        </section>
    );
}

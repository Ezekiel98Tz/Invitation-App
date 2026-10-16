import { useCallback, useEffect, useRef } from 'react';

interface GuardedRequest {
    signal: AbortSignal;
    isCurrent: () => boolean;
}

/**
 * Guards list/search requests so a slower, older response can never overwrite a
 * newer one.
 *
 * Each `begin()` cancels the previous in-flight request and returns a fresh
 * `AbortSignal` plus an `isCurrent()` check for the response handler. This
 * mirrors the request-sequence pattern used by the pledge-import page and adds
 * real cancellation, so an aborted request is never surfaced as an error.
 */
export function useRequestGuard() {
    const controllerRef = useRef<AbortController | null>(null);
    const sequenceRef = useRef(0);

    useEffect(
        () => () => {
            controllerRef.current?.abort();
        },
        [],
    );

    return useCallback((): GuardedRequest => {
        controllerRef.current?.abort();

        const controller = new AbortController();
        controllerRef.current = controller;
        const sequence = ++sequenceRef.current;

        return {
            signal: controller.signal,
            isCurrent: () => sequence === sequenceRef.current,
        };
    }, []);
}

import { api, apiErrorMessage } from './api';

/**
 * File-download helpers for the operator spreadsheet endpoints.
 *
 * These endpoints stream a file on success but can answer with a JSON error
 * (for example a 503 when the PHP zip extension is missing). Because the
 * request asks for a blob, that error body arrives as a Blob and would not be
 * readable through the normal error helpers, so it is parsed here explicitly.
 */

export function filenameFromDisposition(disposition: string | undefined, fallback: string): string {
    if (!disposition) return fallback;
    const match = /filename\*?=(?:UTF-8'')?["']?([^"';]+)/i.exec(disposition);
    return match ? decodeURIComponent(match[1]) : fallback;
}

async function messageFromBlob(blob: Blob): Promise<string | null> {
    try {
        const json = JSON.parse(await blob.text()) as { message?: string };
        return typeof json?.message === 'string' ? json.message : null;
    } catch {
        return null;
    }
}

/** Turn a failed blob download into the clearest message available. */
export async function describeDownloadError(err: unknown, fallback: string): Promise<string> {
    const data = (err as { response?: { data?: unknown } })?.response?.data;
    if (data instanceof Blob) {
        const message = await messageFromBlob(data);
        if (message) return message;
    }
    return apiErrorMessage(err) || fallback;
}

/** Save an already-fetched blob using the browser's download behaviour. */
export function saveBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}

/**
 * Download a file from the API and return the filename that was used. Throws a
 * human-readable error when the endpoint answers with an error payload instead
 * of a file.
 */
export async function downloadFile(url: string, fallbackName: string): Promise<string> {
    const response = await api.get(url, { responseType: 'blob' });
    const contentType = String(response.headers['content-type'] ?? '');
    const blob = response.data as Blob;

    if (!blob || blob.size === 0) {
        throw new Error('The download returned an empty file.');
    }

    if (/json/i.test(contentType)) {
        const message = await messageFromBlob(blob);
        throw new Error(message ?? 'The download did not return a file.');
    }

    const filename = filenameFromDisposition(
        String(response.headers['content-disposition'] ?? ''),
        fallbackName,
    );
    saveBlob(blob, filename);
    return filename;
}

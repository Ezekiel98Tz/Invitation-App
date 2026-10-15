import axios, { AxiosError } from 'axios';

/**
 * Same-origin API client. `withCredentials` ensures the Laravel session cookie
 * is sent; axios automatically echoes the XSRF-TOKEN cookie back as the
 * X-XSRF-TOKEN header for same-origin requests.
 */
export const api = axios.create({
    baseURL: '',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/** Establish the session + XSRF-TOKEN cookie before a state-changing request. */
export async function ensureCsrf(): Promise<void> {
    await api.get('/api/v1/csrf');
}

export function apiErrorMessage(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as Record<string, unknown> | undefined;
        if (data && typeof data.message === 'string' && data.message.length > 0) {
            return data.message;
        }
        const errors = data?.errors as Record<string, string[]> | undefined;
        if (errors) {
            const first = Object.values(errors)[0];
            if (Array.isArray(first) && first[0]) {
                return String(first[0]);
            }
        }
        return error.message;
    }
    return 'An unexpected error occurred.';
}

export function validationErrors(error: unknown): Record<string, string[]> {
    if (axios.isAxiosError(error)) {
        const data = (error as AxiosError).response?.data as Record<string, unknown> | undefined;
        const errors = data?.errors as Record<string, string[]> | undefined;
        if (errors) {
            return errors;
        }
    }
    return {};
}

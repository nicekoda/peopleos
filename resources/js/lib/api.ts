import axios, { AxiosError } from 'axios';

/**
 * Shared axios instance for talking to /api/v1 from React (Checkpoint 17).
 * withCredentials carries the session cookie; axios's own defaults
 * (xsrfCookieName/xsrfHeaderName = XSRF-TOKEN/X-XSRF-TOKEN) already match
 * Laravel's CSRF cookie convention, so no custom CSRF wiring is needed.
 *
 * This is deliberately generic — future modules (Leave, Documents,
 * Policies) should reuse this same instance and `toApiError()` rather
 * than each rolling their own error handling.
 *
 * Security note: this client only ever talks to endpoints that are
 * independently protected by the backend (auth, tenant.matches,
 * permission:{key}, tenant/object-level checks). Nothing here decides
 * what a request is allowed to do — it only decides how to *display*
 * whatever the backend already decided. See docs/security.md.
 */
export const api = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    headers: {
        Accept: 'application/json',
    },
});

export interface ApiError {
    status: number;
    message: string;
    /** Field-level validation errors from a 422 response, if any. */
    errors?: Record<string, string[]>;
}

/**
 * Normalizes any axios failure into a safe, displayable shape. Never
 * surfaces a raw stack trace or raw response body to the caller — only
 * the specific fields this type exposes.
 */
export function toApiError(error: unknown): ApiError {
    if (axios.isAxiosError(error)) {
        const axiosError = error as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
        const status = axiosError.response?.status ?? 0;

        switch (status) {
            case 401:
                return { status, message: 'Your session has expired. Please log in again.' };
            case 403:
                return { status, message: "You don't have permission to do this." };
            case 404:
                return { status, message: 'Not found.' };
            case 409:
                return { status, message: axiosError.response?.data?.message ?? 'This action conflicts with the current state.' };
            case 422:
                return {
                    status,
                    message: 'Please fix the errors below.',
                    errors: axiosError.response?.data?.errors,
                };
            default:
                return { status, message: 'Something went wrong. Please try again.' };
        }
    }

    return { status: 0, message: 'Something went wrong. Please try again.' };
}

/**
 * A 401 means the session is gone — the only case where the shared
 * helper takes an action itself (a full-page redirect) rather than just
 * returning a displayable error, since there's no useful in-page state
 * to show once the session is invalid.
 */
export function redirectIfUnauthenticated(apiError: ApiError): boolean {
    if (apiError.status === 401) {
        window.location.href = '/login';

        return true;
    }

    return false;
}

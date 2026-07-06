import axios from 'axios';
import { api, toApiError, ApiError } from './api';

/**
 * Safe authenticated file download (Checkpoint 19, Refinement 5).
 *
 * Deliberately does NOT navigate the browser to the download URL
 * directly (`window.location = url` or a plain `<a href>` to the API) —
 * that would send an unauthenticated-looking top-level navigation
 * outside axios's `withCredentials`/CSRF setup for some browser
 * configurations, and more importantly would give the browser no chance
 * to distinguish "here is a file" from "here is a 403 JSON error body,
 * which the browser will happily save as a file named after the
 * document." Fetching via `api` (the same authenticated axios instance
 * every other request uses) and only creating a blob URL after a real
 * 2xx response avoids both problems.
 */
export async function downloadEmployeeDocument(
    employeeId: string,
    documentId: string,
    filename: string,
): Promise<ApiError | null> {
    try {
        const response = await api.get(`/employees/${employeeId}/documents/${documentId}/download`, {
            responseType: 'blob',
        });

        const url = URL.createObjectURL(response.data as Blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);

        return null;
    } catch (error) {
        return toDownloadApiError(error);
    }
}

/**
 * Checkpoint 35 — Option B: the PDF is rendered on demand server-side,
 * never stored, so this is just another authenticated blob download,
 * same shape as downloadEmployeeDocument above.
 */
export async function downloadHrGeneratedDocumentPdf(documentId: string, filename: string): Promise<ApiError | null> {
    try {
        const response = await api.get(`/hr-generated-documents/${documentId}/download-pdf`, {
            responseType: 'blob',
        });

        const url = URL.createObjectURL(response.data as Blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);

        return null;
    } catch (error) {
        return toDownloadApiError(error);
    }
}

/**
 * With `responseType: 'blob'`, a failed request's `error.response.data`
 * is itself a Blob (containing the JSON error body as raw bytes), not
 * parsed JSON — `toApiError()` can't read `.message`/`.errors` off a
 * Blob directly. Re-parse it as text/JSON first, so a 403/404 still
 * produces the same safe, generic message every other API call gets,
 * instead of either a blank message or (worse) the blob silently being
 * offered to the user as if it were the downloaded file.
 */
async function toDownloadApiError(error: unknown): Promise<ApiError> {
    if (axios.isAxiosError(error) && error.response?.data instanceof Blob) {
        try {
            const text = await error.response.data.text();
            error.response.data = JSON.parse(text);
        } catch {
            // Not JSON (e.g. an empty error body) — fall through to
            // toApiError()'s generic default message.
        }
    }

    return toApiError(error);
}

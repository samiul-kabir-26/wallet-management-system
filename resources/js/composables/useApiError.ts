export interface ParsedApiError {
    message: string;
    fieldErrors: Record<string, string>;
}

interface ApiErrorShape {
    response?: {
        data?: {
            message?: string;
            errors?: Record<string, string[] | string>;
        };
    };
    message?: string;
}

/**
 * Normalise an Axios/Laravel error into a message plus first-error-per-field map
 * (Laravel's 422 shape: { message, errors: { field: [msg, ...] } }).
 */
export function parseApiError(err: unknown, fallback = 'Something went wrong.'): ParsedApiError {
    const e = err as ApiErrorShape;
    const data = e?.response?.data;
    const fieldErrors: Record<string, string> = {};

    for (const [field, value] of Object.entries(data?.errors ?? {})) {
        fieldErrors[field] = Array.isArray(value) ? (value[0] ?? '') : value;
    }

    return {
        message: data?.message || e?.message || fallback,
        fieldErrors,
    };
}

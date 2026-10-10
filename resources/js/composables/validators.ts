export type Rule = (value: unknown, ctx?: { form: Record<string, unknown> }) => true | string;

const isBlank = (value: unknown): boolean => value === undefined || value === null || String(value).trim() === '';

export const required =
    (label: string): Rule =>
    (value) =>
        isBlank(value) ? `${label} is required.` : true;

export const positiveInteger =
    (label: string): Rule =>
    (value) => {
        if (isBlank(value)) {
            return `${label} is required.`;
        }
        return /^[1-9]\d*$/.test(String(value)) ? true : `${label} must be a positive whole number.`;
    };

export const money =
    (label = 'Amount', max?: number): Rule =>
    (value) => {
        if (isBlank(value)) {
            return `${label} is required.`;
        }
        if (!/^\d+(\.\d{1,2})?$/.test(String(value))) {
            return `${label} must be a number with at most 2 decimal places.`;
        }
        const n = Number(value);
        if (n <= 0) {
            return `${label} must be greater than zero.`;
        }
        if (max !== undefined && n > max) {
            return `${label} cannot exceed ${max}.`;
        }
        return true;
    };

/** PIN: 5-10 digit numeric, per the project's authentication spec. */
export const pin: Rule = (value) => {
    if (isBlank(value)) {
        return 'PIN is required.';
    }
    return /^\d{5,10}$/.test(String(value)) ? true : 'PIN must be 5 to 10 digits.';
};

export const pinConfirmation =
    (pinField = 'pin'): Rule =>
    (value, ctx) => {
        if (isBlank(value)) {
            return 'Please confirm the PIN.';
        }
        return String(value) === String(ctx?.form[pinField]) ? true : 'PINs do not match.';
    };

export const phone: Rule = (value) => {
    if (isBlank(value)) {
        return 'Phone number is required.';
    }
    return /^\+?\d{10,15}$/.test(String(value).trim()) ? true : 'Enter a valid phone number (10-15 digits).';
};

export const otp: Rule = (value) => {
    if (isBlank(value)) {
        return 'OTP code is required.';
    }
    return /^\d{6}$/.test(String(value)) ? true : 'OTP must be exactly 6 digits.';
};

export const rate =
    (label: string): Rule =>
    (value) => {
        if (isBlank(value)) {
            return `${label} is required.`;
        }
        const n = Number(value);
        return Number.isFinite(n) && n >= 0 && n <= 1 ? true : `${label} must be between 0 and 1.`;
    };

export const email: Rule = (value) => {
    if (isBlank(value)) {
        return 'Email is required.';
    }
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value).trim()) ? true : 'Enter a valid email address.';
};

export const password =
    (min = 8): Rule =>
    (value) => {
        if (isBlank(value)) {
            return 'Password is required.';
        }
        return String(value).length >= min ? true : `Password must be at least ${min} characters.`;
    };

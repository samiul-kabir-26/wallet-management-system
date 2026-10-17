<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { useForm } from 'vee-validate';
import { api } from '@/services/api';
import { useToastStore } from '@/stores/toast';
import { parseApiError } from '@/composables/useApiError';
import { otp, type Rule } from '@/composables/validators';
import TextField from '@/components/Forms/TextField.vue';
import type { User } from '@/types/models';

const RESEND_SECONDS = 60;

const emit = defineEmits<{
    (e: 'added', user: User): void;
}>();

const bangladeshiMobile: Rule = (value) =>
    /^01[3-9]\d{8}$/.test(String(value ?? '').trim()) ? true : 'Enter an 11-digit mobile number, e.g. 01712345678.';

const toast = useToastStore();
const sentTo = ref<string | null>(null);
const resendIn = ref(0);
let resendTimer: ReturnType<typeof setInterval> | null = null;

// One form for both steps: TextField binds to the nearest form, so a second useForm would capture its fields
const { handleSubmit, isSubmitting, setErrors, setFieldValue } = useForm<{ phone_number: string; otp_code: string }>({
    validationSchema: computed(() => (sentTo.value ? { otp_code: otp } : { phone_number: bangladeshiMobile })),
    initialValues: { phone_number: '', otp_code: '' },
});

const startResendCountdown = (): void => {
    resendIn.value = RESEND_SECONDS;
    if (resendTimer) {
        clearInterval(resendTimer);
    }
    resendTimer = setInterval(() => {
        resendIn.value -= 1;
        if (resendIn.value <= 0 && resendTimer) {
            clearInterval(resendTimer);
            resendTimer = null;
        }
    }, 1000);
};

const requestCode = async (phoneNumber: string): Promise<void> => {
    try {
        const res = await api.post('/users/me/phone', { phone_number: phoneNumber });
        sentTo.value = phoneNumber;
        setFieldValue('otp_code', '');
        startResendCountdown();
        toast.success(res.data.message ?? 'Code sent.');
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Could not send the code.');
        if (sentTo.value) {
            toast.error(fieldErrors.phone_number ?? message);
        } else {
            setErrors({ phone_number: fieldErrors.phone_number ?? message });
        }
    }
};

const sendCode = handleSubmit((values) => requestCode(values.phone_number.trim()));

const resendCode = (): void => {
    if (sentTo.value) {
        void requestCode(sentTo.value);
    }
};

const confirm = handleSubmit(async (values) => {
    try {
        const res = await api.post('/users/me/phone/confirm', { otp_code: values.otp_code });
        toast.success(res.data.message ?? 'Phone number added.');
        emit('added', res.data.data.user as User);
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Could not add the phone number.');
        // The number was taken by another account after the code was sent: start over
        if (fieldErrors.phone_number) {
            sentTo.value = null;
            toast.error(fieldErrors.phone_number);
            return;
        }
        setErrors({ otp_code: fieldErrors.otp_code ?? message });
    }
});

const changeNumber = (): void => {
    sentTo.value = null;
};

onBeforeUnmount(() => {
    if (resendTimer) {
        clearInterval(resendTimer);
    }
});
</script>

<template>
    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="space-y-1">
            <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Add a phone number</p>
            <p class="text-xs text-zinc-500">
                Once verified, you can sign in with this number instead of your email. It cannot be changed here afterwards, and a
                number that already belongs to another account cannot be used.
            </p>
        </div>

        <form v-if="!sentTo" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start" novalidate @submit.prevent="sendCode">
            <div class="sm:w-64">
                <TextField name="phone_number" label="Mobile number" type="tel" inputmode="tel" autocomplete="tel" placeholder="01712345678" />
            </div>
            <button
                type="submit"
                :disabled="isSubmitting"
                class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 disabled:opacity-50 sm:mt-5"
            >
                {{ isSubmitting ? 'Sending...' : 'Send Code' }}
            </button>
        </form>

        <form v-else class="mt-4 space-y-3" novalidate @submit.prevent="confirm">
            <p class="text-xs text-zinc-600 dark:text-zinc-400">
                Code sent to <span class="font-medium text-zinc-900 dark:text-zinc-200">{{ sentTo }}</span>.
                <button type="button" class="ml-1 font-medium text-indigo-600 hover:underline dark:text-indigo-400" @click="changeNumber">
                    Use a different number
                </button>
            </p>
            <div class="max-w-xs">
                <TextField name="otp_code" label="Verification code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" />
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    :disabled="isSubmitting"
                    class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 disabled:opacity-50"
                >
                    {{ isSubmitting ? 'Verifying...' : 'Verify & Add' }}
                </button>
                <button
                    type="button"
                    :disabled="resendIn > 0"
                    class="text-xs font-medium text-indigo-600 hover:underline disabled:cursor-not-allowed disabled:text-zinc-400 disabled:no-underline dark:text-indigo-400"
                    @click="resendCode"
                >
                    {{ resendIn > 0 ? `Resend code in ${resendIn}s` : 'Resend code' }}
                </button>
            </div>
        </form>
    </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { useForm } from 'vee-validate';
import { api } from '@/services/api';
import { useToastStore } from '@/stores/toast';
import { parseApiError } from '@/composables/useApiError';
import { otp } from '@/composables/validators';
import TextField from '@/components/Forms/TextField.vue';
import type { User } from '@/types/models';

const RESEND_SECONDS = 60;

defineProps<{
    phoneNumber: string;
}>();

const emit = defineEmits<{
    (e: 'verified', user: User): void;
}>();

const toast = useToastStore();
const codeSent = ref(false);
const sending = ref(false);
const resendIn = ref(0);
let resendTimer: ReturnType<typeof setInterval> | null = null;

const { handleSubmit, isSubmitting, resetForm, setErrors } = useForm<{ otp_code: string }>({
    validationSchema: { otp_code: otp },
    initialValues: { otp_code: '' },
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

const sendCode = async (): Promise<void> => {
    sending.value = true;
    try {
        const res = await api.post('/users/me/phone-verification');
        codeSent.value = true;
        resetForm();
        startResendCountdown();
        toast.success(res.data.message ?? 'Code sent.');
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Could not send the code.').message);
    } finally {
        sending.value = false;
    }
};

const confirm = handleSubmit(async (values) => {
    try {
        const res = await api.post('/users/me/phone-verification/confirm', values);
        toast.success(res.data.message ?? 'Phone number verified.');
        emit('verified', res.data.data.user as User);
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Verification failed.');
        setErrors({ otp_code: fieldErrors.otp_code ?? message });
    }
});

onBeforeUnmount(() => {
    if (resendTimer) {
        clearInterval(resendTimer);
    }
});
</script>

<template>
    <div class="rounded-2xl border border-amber-200 bg-amber-50/60 p-6 dark:border-amber-900/60 dark:bg-amber-950/20">
        <div class="space-y-1">
            <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Verify your phone number</p>
            <p class="text-xs text-zinc-600 dark:text-zinc-400">
                We will text a 6-digit code to <span class="font-medium text-zinc-900 dark:text-zinc-200">{{ phoneNumber }}</span>.
                A verified number is required for some account changes, such as staff access.
            </p>
        </div>

        <div v-if="!codeSent" class="mt-4">
            <button
                type="button"
                :disabled="sending"
                class="rounded-lg bg-amber-600 px-5 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-amber-500 disabled:opacity-50"
                @click="sendCode"
            >
                {{ sending ? 'Sending...' : 'Send Code' }}
            </button>
        </div>

        <form v-else class="mt-4 space-y-3" novalidate @submit.prevent="confirm">
            <div class="max-w-xs">
                <TextField name="otp_code" label="Verification code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" />
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    :disabled="isSubmitting"
                    class="rounded-lg bg-amber-600 px-5 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-amber-500 disabled:opacity-50"
                >
                    {{ isSubmitting ? 'Verifying...' : 'Verify' }}
                </button>
                <button
                    type="button"
                    :disabled="sending || resendIn > 0"
                    class="text-xs font-medium text-amber-700 hover:underline disabled:cursor-not-allowed disabled:text-zinc-400 disabled:no-underline dark:text-amber-400"
                    @click="sendCode"
                >
                    {{ resendIn > 0 ? `Resend code in ${resendIn}s` : 'Resend code' }}
                </button>
            </div>
        </form>
    </div>
</template>

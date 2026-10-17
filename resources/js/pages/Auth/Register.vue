<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import { parseApiError } from '@/composables/useApiError';
import { otp, phone, pin, pinConfirmation, required } from '@/composables/validators';
import AuthLayout from '@/layouts/AuthLayout.vue';
import TextField from '@/components/Forms/TextField.vue';

const RESEND_SECONDS = 60;

const authStore = useAuthStore();
const toast = useToastStore();
const role = ref<'USER' | 'AGENT'>('USER');

// Step 1 collects the details and texts a code; step 2 confirms the code and creates the account
const step = ref<'details' | 'code'>('details');
const sendingCode = ref(false);
const resendIn = ref(0);
let resendTimer: ReturnType<typeof setInterval> | null = null;

const { handleSubmit, setErrors, values } = useForm<{ name: string; phone: string; pin: string; pinConfirmation: string; otpCode: string }>({
    validationSchema: computed(() =>
        step.value === 'details'
            ? { name: required('Full name'), phone, pin, pinConfirmation: pinConfirmation('pin') }
            : { otpCode: otp },
    ),
    initialValues: { name: '', phone: '', pin: '', pinConfirmation: '', otpCode: '' },
    // The detail fields are hidden on step 2 but their values are still submitted
    keepValuesOnUnmount: true,
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

const sendCode = async (phoneNumber: string): Promise<boolean> => {
    sendingCode.value = true;
    try {
        const res = await api.post('/auth/register/request-otp', { phone_number: phoneNumber });
        startResendCountdown();
        toast.success(res.data.message ?? 'Code sent.');
        return true;
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Could not send the code.');
        if (step.value === 'details') {
            setErrors({ phone: fieldErrors.phone_number ?? message });
        } else {
            toast.error(fieldErrors.phone_number ?? message);
        }
        return false;
    } finally {
        sendingCode.value = false;
    }
};

const requestCode = handleSubmit(async (formValues) => {
    if (await sendCode(formValues.phone.trim())) {
        step.value = 'code';
    }
});

const resendCode = (): void => {
    void sendCode(values.phone.trim());
};

const editDetails = (): void => {
    step.value = 'details';
};

const handleRegister = handleSubmit(async (formValues) => {
    try {
        await authStore.register({
            name: formValues.name,
            phone_number: formValues.phone.trim(),
            pin: formValues.pin,
            pin_confirmation: formValues.pinConfirmation,
            role: role.value,
            otp_code: formValues.otpCode,
        });
        toast.success('Account created successfully.');
        router.visit(role.value === 'USER' ? '/user/dashboard' : '/agent/dashboard');
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Registration failed.');
        // Details rejected by the server (e.g. the number was taken meanwhile): go back and show them
        if (fieldErrors.name || fieldErrors.phone_number || fieldErrors.pin) {
            step.value = 'details';
            setErrors({ name: fieldErrors.name, phone: fieldErrors.phone_number, pin: fieldErrors.pin });
            toast.error(message);
            return;
        }
        setErrors({ otpCode: fieldErrors.otp_code ?? message });
    }
});

onBeforeUnmount(() => {
    if (resendTimer) {
        clearInterval(resendTimer);
    }
});
</script>

<template>
    <Head title="Create Account - WalletMS" />
    <AuthLayout title="Create an account" subtitle="Join the digital wallet network">
        <form v-if="step === 'details'" class="space-y-4" novalidate @submit.prevent="requestCode">
            <!-- Account Role Selection -->
            <div>
                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Register As</label>
                <div class="mt-1 grid grid-cols-2 gap-3">
                    <label
                        class="flex items-center justify-center gap-2 rounded-lg border p-3 cursor-pointer text-xs font-semibold transition"
                        :class="
                            role === 'USER'
                                ? 'border-indigo-600 bg-indigo-50 text-indigo-700 dark:border-indigo-500 dark:bg-indigo-950/40 dark:text-indigo-300'
                                : 'border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800'
                        "
                    >
                        <input v-model="role" type="radio" value="USER" class="sr-only" />
                        <span>Customer (50৳ Bonus)</span>
                    </label>

                    <label
                        class="flex items-center justify-center gap-2 rounded-lg border p-3 cursor-pointer text-xs font-semibold transition"
                        :class="
                            role === 'AGENT'
                                ? 'border-amber-600 bg-amber-50 text-amber-700 dark:border-amber-500 dark:bg-amber-950/40 dark:text-amber-300'
                                : 'border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800'
                        "
                    >
                        <input v-model="role" type="radio" value="AGENT" class="sr-only" />
                        <span>Financial Agent</span>
                    </label>
                </div>
            </div>

            <TextField name="name" label="Full Name" autocomplete="name" placeholder="Alice Rahman" />
            <TextField name="phone" label="Phone Number (BD Format)" type="tel" inputmode="tel" autocomplete="tel" placeholder="01712345678" />

            <div class="grid grid-cols-2 gap-3">
                <TextField name="pin" label="PIN (5-10 digits)" type="password" inputmode="numeric" autocomplete="new-password" placeholder="•••••" />
                <TextField name="pinConfirmation" label="Confirm PIN" type="password" inputmode="numeric" autocomplete="new-password" placeholder="•••••" />
            </div>

            <button
                type="submit"
                :disabled="sendingCode"
                class="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 focus:outline-none disabled:opacity-50"
            >
                {{ sendingCode ? 'Sending code...' : 'Continue' }}
            </button>
            <p class="text-center text-[11px] text-zinc-500">We will text a 6-digit code to confirm the number is yours.</p>

            <div class="text-center text-xs text-zinc-500 dark:text-zinc-400 pt-2">
                Already registered?
                <Link href="/login" class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                    Sign in here
                </Link>
            </div>
        </form>

        <form v-else class="space-y-4" novalidate @submit.prevent="handleRegister">
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Enter the 6-digit code we sent to
                <span class="font-medium text-zinc-900 dark:text-zinc-200">{{ values.phone }}</span>.
                <button type="button" class="ml-1 text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400" @click="editDetails">
                    Edit details
                </button>
            </p>

            <TextField name="otpCode" label="Verification code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" />

            <button
                type="submit"
                :disabled="authStore.loading"
                class="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 focus:outline-none disabled:opacity-50"
            >
                {{ authStore.loading ? 'Creating account...' : 'Verify & Create Account' }}
            </button>

            <div class="text-center">
                <button
                    type="button"
                    :disabled="sendingCode || resendIn > 0"
                    class="text-xs font-medium text-indigo-600 hover:underline disabled:cursor-not-allowed disabled:text-zinc-400 disabled:no-underline dark:text-indigo-400"
                    @click="resendCode"
                >
                    {{ resendIn > 0 ? `Resend code in ${resendIn}s` : 'Resend code' }}
                </button>
            </div>
        </form>
    </AuthLayout>
</template>

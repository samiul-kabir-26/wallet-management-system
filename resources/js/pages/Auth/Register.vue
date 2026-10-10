<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import { parseApiError } from '@/composables/useApiError';
import { phone, pin, pinConfirmation, required } from '@/composables/validators';
import AuthLayout from '@/layouts/AuthLayout.vue';
import TextField from '@/components/Forms/TextField.vue';

const authStore = useAuthStore();
const toast = useToastStore();
const role = ref<'USER' | 'AGENT'>('USER');

const { handleSubmit, setErrors } = useForm<{ name: string; phone: string; pin: string; pinConfirmation: string }>({
    validationSchema: {
        name: required('Full name'),
        phone,
        pin,
        pinConfirmation: pinConfirmation('pin'),
    },
    initialValues: { name: '', phone: '', pin: '', pinConfirmation: '' },
});

const handleRegister = handleSubmit(async (formValues) => {
    try {
        await authStore.register({
            name: formValues.name,
            phone_number: formValues.phone,
            pin: formValues.pin,
            pin_confirmation: formValues.pinConfirmation,
            role: role.value,
        });
        toast.success('Account created successfully.');
        router.visit(role.value === 'USER' ? '/user/dashboard' : '/agent/dashboard');
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Registration failed.');
        const mapped: Record<string, string> = {};
        if (fieldErrors.name) mapped.name = fieldErrors.name;
        if (fieldErrors.phone_number) mapped.phone = fieldErrors.phone_number;
        if (fieldErrors.pin) mapped.pin = fieldErrors.pin;
        setErrors(mapped);
        toast.error(message);
    }
});
</script>

<template>
    <Head title="Create Account - WalletMS" />
    <AuthLayout title="Create an account" subtitle="Join the digital wallet network">
        <form class="space-y-4" novalidate @submit.prevent="handleRegister">
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
                :disabled="authStore.loading"
                class="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 focus:outline-none disabled:opacity-50"
            >
                <span v-if="authStore.loading">Creating account...</span>
                <span v-else>Register & Get Started</span>
            </button>

            <div class="text-center text-xs text-zinc-500 dark:text-zinc-400 pt-2">
                Already registered?
                <Link href="/login" class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                    Sign in here
                </Link>
            </div>
        </form>
    </AuthLayout>
</template>

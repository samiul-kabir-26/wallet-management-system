<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useAuthStore } from '@/stores/auth';
import AuthLayout from '@/layouts/AuthLayout.vue';
import Alert from '@/components/Alert.vue';

const authStore = useAuthStore();

const name = ref('');
const phone = ref('');
const pin = ref('');
const pinConfirmation = ref('');
const role = ref<'USER' | 'AGENT'>('USER');

const error = ref<string | null>(null);
const validationErrors = ref<Record<string, string[]>>({});

const handleRegister = async () => {
    error.value = null;
    validationErrors.value = {};

    if (pin.value !== pinConfirmation.value) {
        error.value = 'Security PIN confirmation does not match.';
        return;
    }

    try {
        const data = await authStore.register({
            name: name.value,
            phone_number: phone.value,
            pin: pin.value,
            pin_confirmation: pinConfirmation.value,
            role: role.value,
        });

        if (role.value === 'USER') {
            router.visit('/user/dashboard');
        } else {
            router.visit('/agent/dashboard');
        }
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
        error.value = apiError.response?.data?.message || 'Registration failed.';
        if (apiError.response?.data?.errors) {
            validationErrors.value = apiError.response.data.errors;
        }
    }
};
</script>

<template>
    <Head title="Create Account - WalletMS" />
    <AuthLayout title="Create an account" subtitle="Join the digital wallet network">
        <div v-if="error" class="mb-4">
            <Alert type="error" :message="error" dismissible @close="error = null" />
        </div>

        <form class="space-y-4" @submit.prevent="handleRegister">
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

            <div>
                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Full Name</label>
                <div class="mt-1">
                    <input
                        v-model="name"
                        type="text"
                        required
                        placeholder="Alice Rahman"
                        class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    />
                    <p v-if="validationErrors.name" class="mt-1 text-xs text-rose-600">{{ validationErrors.name[0] }}</p>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Phone Number (BD Format)</label>
                <div class="mt-1">
                    <input
                        v-model="phone"
                        type="tel"
                        required
                        placeholder="01712345678"
                        class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    />
                    <p v-if="validationErrors.phone_number" class="mt-1 text-xs text-rose-600">{{ validationErrors.phone_number[0] }}</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">5-Digit PIN</label>
                    <div class="mt-1">
                        <input
                            v-model="pin"
                            type="password"
                            required
                            maxlength="5"
                            placeholder="•••••"
                            class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm tracking-widest text-zinc-900 placeholder-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Confirm PIN</label>
                    <div class="mt-1">
                        <input
                            v-model="pinConfirmation"
                            type="password"
                            required
                            maxlength="5"
                            placeholder="•••••"
                            class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm tracking-widest text-zinc-900 placeholder-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                </div>
            </div>
            <p v-if="validationErrors.pin" class="text-xs text-rose-600">{{ validationErrors.pin[0] }}</p>

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

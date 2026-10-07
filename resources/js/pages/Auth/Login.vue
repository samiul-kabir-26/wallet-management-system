<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useAuthStore } from '@/stores/auth';
import AuthLayout from '@/layouts/AuthLayout.vue';
import Alert from '@/components/Alert.vue';
import Modal from '@/components/Modal.vue';

const authStore = useAuthStore();

// Login Modes: 'user_agent' (Customer/Agent PIN) or 'admin' (Admin 2FA)
const mode = ref<'user_agent' | 'admin'>('user_agent');

// Customer / Agent credentials
const phone = ref('');
const pin = ref('');
const isAgentLogin = ref(false);

// Admin credentials
const adminIdentifier = ref('');
const adminPassword = ref('');
const adminEmail = ref('');
const otpCode = ref('');
const showOtpModal = ref(false);

const error = ref<string | null>(null);
const successMessage = ref<string | null>(null);

const handleUserAgentLogin = async () => {
    error.value = null;
    successMessage.value = null;
    try {
        if (isAgentLogin.value) {
            await authStore.loginAgent(phone.value, pin.value);
            router.visit('/agent/dashboard');
        } else {
            await authStore.loginUser(phone.value, pin.value);
            router.visit('/user/dashboard');
        }
    } catch (err: unknown) {
        error.value = (err as Error).message;
    }
};

const handleAdminLoginStep1 = async () => {
    error.value = null;
    successMessage.value = null;
    try {
        const msg = await authStore.adminLogin(adminIdentifier.value, adminPassword.value);
        adminEmail.value = adminIdentifier.value; // Store for Step 2
        successMessage.value = msg;
        showOtpModal.value = true;
    } catch (err: unknown) {
        error.value = (err as Error).message;
    }
};

const handleAdminVerifyOtp = async () => {
    error.value = null;
    try {
        await authStore.adminVerifyOtp(adminEmail.value, otpCode.value);
        showOtpModal.value = false;
        router.visit('/admin/dashboard');
    } catch (err: unknown) {
        error.value = (err as Error).message;
    }
};
</script>

<template>
    <Head title="Sign In - WalletMS" />
    <AuthLayout title="Welcome back" subtitle="Sign in to your digital wallet account">
        <!-- Mode Tabs -->
        <div class="mb-6 flex rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800">
            <button
                type="button"
                class="flex-1 rounded-lg py-2 text-xs font-semibold transition"
                :class="
                    mode === 'user_agent'
                        ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-900 dark:text-zinc-100'
                        : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400'
                "
                @click="mode = 'user_agent'"
            >
                Customer / Agent
            </button>
            <button
                type="button"
                class="flex-1 rounded-lg py-2 text-xs font-semibold transition"
                :class="
                    mode === 'admin'
                        ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-900 dark:text-zinc-100'
                        : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400'
                "
                @click="mode = 'admin'"
            >
                Staff / Admin
            </button>
        </div>

        <!-- Feedback Alert -->
        <div v-if="error" class="mb-4">
            <Alert type="error" :message="error" dismissible @close="error = null" />
        </div>
        <div v-if="successMessage && !showOtpModal" class="mb-4">
            <Alert type="success" :message="successMessage" dismissible @close="successMessage = null" />
        </div>

        <!-- Customer / Agent Form -->
        <form v-if="mode === 'user_agent'" class="space-y-4" @submit.prevent="handleUserAgentLogin">
            <div class="flex items-center justify-between pb-1">
                <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Account Type</label>
                <div class="flex items-center gap-3 text-xs">
                    <label class="flex items-center gap-1.5 cursor-pointer">
                        <input v-model="isAgentLogin" type="radio" :value="false" class="text-indigo-600 focus:ring-indigo-500" />
                        <span>Customer</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer">
                        <input v-model="isAgentLogin" type="radio" :value="true" class="text-indigo-600 focus:ring-indigo-500" />
                        <span>Agent</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Phone Number</label>
                <div class="mt-1">
                    <input
                        v-model="phone"
                        type="tel"
                        required
                        placeholder="01712345678"
                        class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    />
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">5-Digit Security PIN</label>
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

            <button
                type="submit"
                :disabled="authStore.loading"
                class="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 focus:outline-none disabled:opacity-50"
            >
                <span v-if="authStore.loading">Authenticating...</span>
                <span v-else>Sign In</span>
            </button>

            <div class="text-center text-xs text-zinc-500 dark:text-zinc-400 pt-2">
                Don't have an account?
                <Link href="/register" class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                    Register here
                </Link>
            </div>
        </form>

        <!-- Admin / Staff Form -->
        <form v-else class="space-y-4" @submit.prevent="handleAdminLoginStep1">
            <div>
                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Admin Email or Phone</label>
                <div class="mt-1">
                    <input
                        v-model="adminIdentifier"
                        type="text"
                        required
                        placeholder="admin@wallet.local"
                        class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    />
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Password</label>
                <div class="mt-1">
                    <input
                        v-model="adminPassword"
                        type="password"
                        required
                        placeholder="••••••••"
                        class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    />
                </div>
            </div>

            <button
                type="submit"
                :disabled="authStore.loading"
                class="w-full rounded-lg bg-rose-600 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-rose-500 focus:outline-none disabled:opacity-50"
            >
                <span v-if="authStore.loading">Verifying credentials...</span>
                <span v-else>Next: Send 2FA Code</span>
            </button>
        </form>

        <!-- 2FA OTP Modal for Admin Login -->
        <Modal :show="showOtpModal" title="Two-Factor Verification (2FA)" @close="showOtpModal = false">
            <div class="space-y-4">
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    A 6-digit verification code has been dispatched to
                    <span class="font-medium text-zinc-900 dark:text-zinc-200">{{ adminEmail }}</span>.
                </p>

                <div v-if="error" class="mb-2">
                    <Alert type="error" :message="error" />
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Enter 6-Digit OTP</label>
                    <input
                        v-model="otpCode"
                        type="text"
                        maxlength="6"
                        required
                        placeholder="123456"
                        class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-center text-lg font-bold tracking-widest text-zinc-900 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    />
                </div>
            </div>

            <template #footer>
                <button
                    type="button"
                    class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    @click="showOtpModal = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    :disabled="authStore.loading || otpCode.length < 6"
                    class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500 disabled:opacity-50"
                    @click="handleAdminVerifyOtp"
                >
                    <span v-if="authStore.loading">Verifying...</span>
                    <span v-else>Confirm & Sign In</span>
                </button>
            </template>
        </Modal>
    </AuthLayout>
</template>

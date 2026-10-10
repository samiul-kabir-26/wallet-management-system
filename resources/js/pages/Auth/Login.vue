<script setup lang="ts">
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import Modal from '@/components/Modal.vue';
import PinLoginForm from '@/components/Forms/PinLoginForm.vue';
import AdminLoginForm from '@/components/Forms/AdminLoginForm.vue';
import OtpVerificationForm from '@/components/Forms/OtpVerificationForm.vue';

// 'user_agent' = Customer/Agent PIN login, 'admin' = password + OTP 2FA
const mode = ref<'user_agent' | 'admin'>('user_agent');
const otpIdentifier = ref('');
const showOtpModal = ref(false);

const handleOtpSent = (identifier: string) => {
    otpIdentifier.value = identifier;
    showOtpModal.value = true;
};
</script>

<template>
    <Head title="Sign In - WalletMS" />
    <AuthLayout title="Welcome back" subtitle="Sign in to your digital wallet account">
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

        <PinLoginForm v-if="mode === 'user_agent'" />
        <AdminLoginForm v-else @otp-sent="handleOtpSent" />

        <Modal :show="showOtpModal" title="Two-Factor Verification (2FA)" @close="showOtpModal = false">
            <OtpVerificationForm :identifier="otpIdentifier" @cancel="showOtpModal = false" />
        </Modal>
    </AuthLayout>
</template>

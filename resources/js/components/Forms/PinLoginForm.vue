<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { useAuthStore, type DeviceChallenge } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import { phone, pin } from '@/composables/validators';
import TextField from '@/components/Forms/TextField.vue';

const authStore = useAuthStore();
const toast = useToastStore();
const isAgentLogin = ref(false);

const { handleSubmit } = useForm<{ phone: string; pin: string }>({
    validationSchema: { phone, pin },
    initialValues: { phone: '', pin: '' },
});

// Set when the PIN was right but this device is new: the account's phone gets an SMS code
const challenge = ref<DeviceChallenge | null>(null);
const deviceCode = ref('');

const goToPanel = (panel: 'user' | 'agent') => router.visit(panel === 'agent' ? '/agent/dashboard' : '/user/dashboard');

const onSubmit = handleSubmit(async (values) => {
    try {
        const result = isAgentLogin.value
            ? await authStore.loginAgent(values.phone, values.pin)
            : await authStore.loginUser(values.phone, values.pin);
        if (result.challenge) {
            challenge.value = result.challenge;
            deviceCode.value = '';
            toast.info(`New device: we sent a code to ${result.challenge.phoneNumber}.`);
            return;
        }
        goToPanel(isAgentLogin.value ? 'agent' : 'user');
    } catch (err: unknown) {
        toast.error((err as Error).message);
    }
});

const confirmDevice = async () => {
    if (!challenge.value || !/^\d{6}$/.test(deviceCode.value)) {
        toast.error('Enter the 6-digit code from the SMS.');
        return;
    }
    try {
        await authStore.verifyDevice(challenge.value, deviceCode.value);
        goToPanel(challenge.value.panel);
    } catch (err: unknown) {
        toast.error((err as Error).message);
    }
};
</script>

<template>
    <form v-if="challenge" class="space-y-4" novalidate @submit.prevent="confirmDevice">
        <p class="text-sm text-zinc-600 dark:text-zinc-300">
            This is the first time this device is used for your account. Enter the 6-digit code we sent to
            <span class="font-medium">{{ challenge.phoneNumber }}</span>. You only need to do this once per device.
        </p>
        <label class="block space-y-1">
            <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">SMS code</span>
            <input
                v-model="deviceCode"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                placeholder="123456"
                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
            />
        </label>
        <div class="flex gap-2">
            <button
                type="button"
                class="flex-1 rounded-lg border border-zinc-300 py-2.5 text-sm font-medium text-zinc-700 dark:border-zinc-700 dark:text-zinc-300"
                @click="challenge = null"
            >
                Back
            </button>
            <button
                type="submit"
                :disabled="authStore.loading"
                class="flex-1 rounded-lg bg-indigo-600 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
            >
                {{ authStore.loading ? 'Verifying...' : 'Verify & Sign In' }}
            </button>
        </div>
    </form>
    <form v-else class="space-y-4" novalidate @submit.prevent="onSubmit">
        <div class="flex items-center justify-between pb-1">
            <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Account Type</label>
            <div class="flex items-center gap-3 text-xs">
                <label class="flex cursor-pointer items-center gap-1.5">
                    <input v-model="isAgentLogin" type="radio" :value="false" class="text-indigo-600 focus:ring-indigo-500" />
                    <span class="text-white">Customer</span>
                </label>
                <label class="flex cursor-pointer items-center gap-1.5">
                    <input v-model="isAgentLogin" type="radio" :value="true" class="text-indigo-600 focus:ring-indigo-500" />
                    <span class="text-white">Agent</span>
                </label>
            </div>
        </div>

        <TextField name="phone" label="Phone Number" type="tel" inputmode="tel" autocomplete="tel" placeholder="01712345678" />
        <TextField name="pin" label="Security PIN (5-10 digits)" type="password" inputmode="numeric" autocomplete="current-password" placeholder="•••••" />

        <button
            type="submit"
            :disabled="authStore.loading"
            class="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 focus:outline-none disabled:opacity-50"
        >
            {{ authStore.loading ? 'Authenticating...' : 'Sign In' }}
        </button>

        <div class="pt-2 text-center text-xs text-zinc-500 dark:text-zinc-400">
            Don't have an account?
            <Link href="/register" class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">Register here</Link>
        </div>
    </form>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api } from '@/services/api';
import { parseApiError } from '@/composables/useApiError';
import { useToastStore } from '@/stores/toast';
import Alert from '@/components/Alert.vue';
import Badge from '@/components/Badge.vue';

/**
 * Staff: sign in with an authenticator app instead of an emailed code.
 *
 * No QR code is drawn: that would need a new package or sending the secret to a third-party service.
 * The setup key (or the otpauth link) is typed or pasted into the app instead.
 */
const toast = useToastStore();
const enabled = ref(false);
const setup = ref<{ secret: string; otpauth_uri: string } | null>(null);
const code = ref('');
const password = ref('');
const error = ref<string | null>(null);
const busy = ref(false);

const load = async () => {
    try {
        enabled.value = Boolean((await api.get('/auth/totp')).data.data.enabled);
    } catch {
        // Leave the card in its default state
    }
};

const run = async (action: () => Promise<void>) => {
    busy.value = true;
    error.value = null;
    try {
        await action();
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Something went wrong.');
        error.value = fieldErrors.code ?? fieldErrors.password ?? message;
    } finally {
        busy.value = false;
    }
};

const start = () => run(async () => {
    setup.value = (await api.post('/auth/totp/setup')).data.data;
    code.value = '';
});

const confirm = () => run(async () => {
    const res = await api.post('/auth/totp/confirm', { code: code.value.trim() });
    toast.success(res.data.message);
    setup.value = null;
    enabled.value = true;
    code.value = '';
});

const disable = () => run(async () => {
    const res = await api.post('/auth/totp/disable', { password: password.value, code: code.value.trim() });
    toast.success(res.data.message);
    enabled.value = false;
    code.value = '';
    password.value = '';
});

const copy = async (text: string) => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Copied.');
    } catch {
        toast.error('Copy failed; select the text instead.');
    }
};

onMounted(load);
</script>

<template>
    <div class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Authenticator app</p>
                <p class="text-xs text-zinc-500">
                    Use Google Authenticator, Microsoft Authenticator or similar for sign-in codes instead of email. Works offline and
                    cannot be intercepted in your inbox.
                </p>
            </div>
            <Badge :variant="enabled ? 'success' : 'neutral'">{{ enabled ? 'ON' : 'OFF' }}</Badge>
        </div>

        <template v-if="!enabled && !setup">
            <button
                type="button"
                :disabled="busy"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                @click="start"
            >
                Set up authenticator app
            </button>
        </template>

        <template v-if="setup">
            <ol class="list-decimal space-y-1 pl-5 text-xs text-zinc-600 dark:text-zinc-300">
                <li>In your app, add an account and choose "Enter a setup key".</li>
                <li>Enter this key (time-based):</li>
            </ol>
            <div class="flex items-center gap-2">
                <code class="flex-1 break-all rounded-lg bg-zinc-100 px-3 py-2 font-mono text-sm tracking-wider dark:bg-zinc-800">{{ setup.secret }}</code>
                <button type="button" class="text-xs font-semibold text-indigo-600 dark:text-indigo-400" @click="copy(setup.secret)">Copy</button>
            </div>
            <button type="button" class="text-xs text-zinc-500 underline" @click="copy(setup.otpauth_uri)">Copy otpauth:// link instead</button>
            <label class="block space-y-1">
                <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Code shown in the app</span>
                <input
                    v-model="code"
                    inputmode="numeric"
                    maxlength="6"
                    autocomplete="one-time-code"
                    class="w-40 rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                />
            </label>
            <button
                type="button"
                :disabled="busy"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                @click="confirm"
            >
                Turn on
            </button>
        </template>

        <template v-if="enabled">
            <p class="text-xs text-zinc-500">To turn it off, confirm your password and a current code.</p>
            <div class="flex flex-wrap items-end gap-2">
                <input
                    v-model="password"
                    type="password"
                    autocomplete="current-password"
                    placeholder="Password"
                    class="rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                />
                <input
                    v-model="code"
                    inputmode="numeric"
                    maxlength="6"
                    placeholder="123456"
                    class="w-28 rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                />
                <button
                    type="button"
                    :disabled="busy"
                    class="rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50 disabled:opacity-50 dark:border-rose-800 dark:text-rose-400"
                    @click="disable"
                >
                    Turn off
                </button>
            </div>
        </template>

        <Alert v-if="error" type="error" :message="error" />
    </div>
</template>

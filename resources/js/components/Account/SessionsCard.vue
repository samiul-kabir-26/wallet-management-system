<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api } from '@/services/api';
import { parseApiError } from '@/composables/useApiError';
import { useToastStore } from '@/stores/toast';
import Badge from '@/components/Badge.vue';
import Skeleton from '@/components/Skeleton.vue';

/**
 * Where the account is signed in, with "sign out" per session, and (customers/agents) the devices
 * proved by SMS code.
 */
const props = defineProps<{ showDevices: boolean }>();

interface Session {
    id: number;
    panel: string | null;
    ip_address: string | null;
    user_agent: string | null;
    created_at: string;
    last_used_at: string | null;
    current: boolean;
}

interface Device {
    id: number;
    label: string | null;
    ip_address: string | null;
    last_used_at: string | null;
    created_at: string;
}

const toast = useToastStore();
const sessions = ref<Session[]>([]);
const devices = ref<Device[]>([]);
const loading = ref(true);

const describe = (userAgent: string | null): string => {
    if (!userAgent) return 'Unknown device';
    const browser = /Edg\//.test(userAgent) ? 'Edge' : /Chrome\//.test(userAgent) ? 'Chrome' : /Firefox\//.test(userAgent) ? 'Firefox' : /Safari\//.test(userAgent) ? 'Safari' : 'Browser';
    const os = /Android/.test(userAgent) ? 'Android' : /iPhone|iPad/.test(userAgent) ? 'iOS' : /Windows/.test(userAgent) ? 'Windows' : /Mac OS/.test(userAgent) ? 'macOS' : /Linux/.test(userAgent) ? 'Linux' : '';
    return os ? `${browser} on ${os}` : browser;
};

const when = (value: string | null): string => (value ? new Date(value).toLocaleString() : '—');

const load = async () => {
    loading.value = true;
    try {
        const requests = [api.get('/auth/sessions'), ...(props.showDevices ? [api.get('/auth/devices')] : [])];
        const [sessionsRes, devicesRes] = await Promise.all(requests);
        sessions.value = sessionsRes.data.data.sessions;
        devices.value = devicesRes?.data.data.devices ?? [];
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to load your sessions.').message);
    } finally {
        loading.value = false;
    }
};

const signOut = async (session: Session) => {
    try {
        await api.delete(`/auth/sessions/${session.id}`);
        toast.success('Session signed out.');
        await load();
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to sign out that session.').message);
    }
};

const forget = async (device: Device) => {
    try {
        await api.delete(`/auth/devices/${device.id}`);
        toast.success('Device removed. Signing in on it will need an SMS code again.');
        await load();
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to remove that device.').message);
    }
};

onMounted(load);
</script>

<template>
    <div class="space-y-5 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div>
            <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Where you're signed in</p>
            <p class="text-xs text-zinc-500">Sign out anything you don't recognise, then change your {{ showDevices ? 'PIN' : 'password' }}.</p>
        </div>
        <Skeleton v-if="loading && sessions.length === 0" :rows="2" height="h-6" />
        <ul v-else class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
            <li v-for="session in sessions" :key="session.id" class="flex items-center justify-between gap-3 py-2">
                <div>
                    <div class="flex items-center gap-2 font-medium text-zinc-900 dark:text-zinc-100">
                        {{ describe(session.user_agent) }}
                        <Badge v-if="session.current" variant="success">This device</Badge>
                    </div>
                    <div class="text-xs text-zinc-500">
                        {{ session.panel }} · {{ session.ip_address ?? 'unknown IP' }} · last active {{ when(session.last_used_at ?? session.created_at) }}
                    </div>
                </div>
                <button
                    v-if="!session.current"
                    type="button"
                    class="shrink-0 rounded-md border border-rose-200 px-3 py-1 text-xs font-semibold text-rose-600 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-400"
                    @click="signOut(session)"
                >
                    Sign out
                </button>
            </li>
        </ul>

        <template v-if="showDevices">
            <div class="border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Trusted devices</p>
                <p class="text-xs text-zinc-500">Signing in from any other device needs a one-time SMS code.</p>
            </div>
            <p v-if="devices.length === 0" class="text-xs text-zinc-500">No trusted devices yet.</p>
            <ul v-else class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                <li v-for="device in devices" :key="device.id" class="flex items-center justify-between gap-3 py-2">
                    <div>
                        <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ describe(device.label) }}</div>
                        <div class="text-xs text-zinc-500">added {{ when(device.created_at) }} · last used {{ when(device.last_used_at) }}</div>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-md border border-zinc-300 px-3 py-1 text-xs font-semibold text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300"
                        @click="forget(device)"
                    >
                        Remove
                    </button>
                </li>
            </ul>
        </template>
    </div>
</template>

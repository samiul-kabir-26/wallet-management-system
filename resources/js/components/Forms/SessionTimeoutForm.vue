<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import { parseApiError } from '@/composables/useApiError';
import Skeleton from '@/components/Skeleton.vue';

type Panel = 'admin' | 'agent' | 'user';

const MIN_MINUTES = 1;
const MAX_MINUTES = 120;

const panels: { key: Panel; label: string; hint: string }[] = [
    { key: 'admin', label: 'Admin panel', hint: 'Staff and administrators' },
    { key: 'agent', label: 'Agent panel', hint: 'Approved agents' },
    { key: 'user', label: 'Customer panel', hint: 'Wallet customers' },
];

const authStore = useAuthStore();
const toast = useToastStore();
const canEdit = authStore.can('settings.manage_session_timeouts');

const loading = ref(true);
const saving = ref(false);
const minutes = reactive<Record<Panel, number>>({ admin: 5, agent: 5, user: 5 });
const errors = reactive<Partial<Record<Panel, string>>>({});

const fetchTimeouts = async () => {
    loading.value = true;
    try {
        const res = await api.get('/system-settings/session-timeouts');
        Object.assign(minutes, res.data.data?.session_timeouts ?? {});
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to load session timeouts.').message);
    } finally {
        loading.value = false;
    }
};

const validate = (): boolean => {
    let valid = true;
    for (const { key } of panels) {
        const value = Number(minutes[key]);
        if (!Number.isInteger(value) || value < MIN_MINUTES || value > MAX_MINUTES) {
            errors[key] = `Enter a whole number from ${MIN_MINUTES} to ${MAX_MINUTES}.`;
            valid = false;
        } else {
            delete errors[key];
        }
    }
    return valid;
};

const save = async () => {
    if (!validate()) {
        return;
    }
    saving.value = true;
    try {
        const res = await api.patch('/system-settings/session-timeouts', {
            admin: Number(minutes.admin),
            agent: Number(minutes.agent),
            user: Number(minutes.user),
        });
        Object.assign(minutes, res.data.data?.session_timeouts ?? {});
        toast.success('Session timeouts updated. They apply to every signed-in session right away.');
        // Pick up a changed admin timeout for this session too
        await authStore.fetchMe();
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to update session timeouts.');
        for (const { key } of panels) {
            if (fieldErrors[key]) {
                errors[key] = fieldErrors[key];
            }
        }
        toast.error(message);
    } finally {
        saving.value = false;
    }
};

onMounted(fetchTimeouts);
</script>

<template>
    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="space-y-1">
            <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Idle Auto-Logout</p>
            <p class="text-xs text-zinc-500">
                Minutes without activity before a session is signed out. The server enforces this too, so a stolen or forgotten
                session stops working after the same time.
            </p>
        </div>

        <Skeleton v-if="loading" class="mt-4" :rows="3" height="h-8" />
        <form v-else class="mt-4 space-y-4" novalidate @submit.prevent="save">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div v-for="panel in panels" :key="panel.key">
                    <label :for="`timeout-${panel.key}`" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">
                        {{ panel.label }}
                    </label>
                    <div class="mt-1 flex items-center gap-2">
                        <input
                            :id="`timeout-${panel.key}`"
                            v-model.number="minutes[panel.key]"
                            type="number"
                            :min="MIN_MINUTES"
                            :max="MAX_MINUTES"
                            step="1"
                            inputmode="numeric"
                            :disabled="!canEdit"
                            class="block w-24 rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                        <span class="text-xs text-zinc-500">min</span>
                    </div>
                    <p class="mt-1 text-[11px] text-zinc-400">{{ panel.hint }}</p>
                    <p v-if="errors[panel.key]" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ errors[panel.key] }}</p>
                </div>
            </div>

            <div v-if="canEdit" class="flex justify-end border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <button
                    type="submit"
                    :disabled="saving"
                    class="rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 disabled:opacity-50"
                >
                    {{ saving ? 'Saving...' : 'Save Timeouts' }}
                </button>
            </div>
            <p v-else class="text-xs text-zinc-500">You can view these values but need the session timeout permission to change them.</p>
        </form>
    </div>
</template>

import { ref } from 'vue';
import { defineStore } from 'pinia';
import { api } from '@/services/api';
import { parseApiError } from '@/composables/useApiError';
import type { AgentInfo, User } from '@/types/models';

export const useUserStore = defineStore('user', () => {
    const profile = ref<User | null>(null);
    const agentInfo = ref<AgentInfo | null>(null);
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);

    const fetchProfile = async (id: number): Promise<User | null> => {
        loading.value = true;
        error.value = null;
        try {
            const res = await api.get(`/users/${id}`);
            const data = res.data.data?.user ?? res.data.data;
            profile.value = data;
            agentInfo.value = data?.agent_info ?? null;
            return data;
        } catch (err: unknown) {
            error.value = parseApiError(err, 'Failed to load profile.').message;
            return null;
        } finally {
            loading.value = false;
        }
    };

    const reset = (): void => {
        profile.value = null;
        agentInfo.value = null;
        error.value = null;
    };

    return { profile, agentInfo, loading, error, fetchProfile, reset };
});

<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { useAuthStore } from '@/stores/auth';
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

const onSubmit = handleSubmit(async (values) => {
    try {
        if (isAgentLogin.value) {
            await authStore.loginAgent(values.phone, values.pin);
            router.visit('/agent/dashboard');
        } else {
            await authStore.loginUser(values.phone, values.pin);
            router.visit('/user/dashboard');
        }
    } catch (err: unknown) {
        toast.error((err as Error).message);
    }
});
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="onSubmit">
        <div class="flex items-center justify-between pb-1">
            <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Account Type</label>
            <div class="flex items-center gap-3 text-xs">
                <label class="flex cursor-pointer items-center gap-1.5">
                    <input v-model="isAgentLogin" type="radio" :value="false" class="text-indigo-600 focus:ring-indigo-500" />
                    <span>Customer</span>
                </label>
                <label class="flex cursor-pointer items-center gap-1.5">
                    <input v-model="isAgentLogin" type="radio" :value="true" class="text-indigo-600 focus:ring-indigo-500" />
                    <span>Agent</span>
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

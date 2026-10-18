<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import AgentLayout from '@/layouts/AgentLayout.vue';
import AccountSettings from '@/components/Account/AccountSettings.vue';

const { isReady } = useAuthGuard('AGENT');
</script>

<template>
    <Head title="My Account" />
    <AgentLayout>
        <div class="mx-auto max-w-3xl">
            <AccountSettings v-if="isReady" panel="agent" />
        </div>
    </AgentLayout>
</template>

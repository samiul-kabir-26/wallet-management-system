<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AccountSettings from '@/components/Account/AccountSettings.vue';

const { isReady } = useAuthGuard('ADMIN');
</script>

<template>
    <Head title="My Account" />
    <AdminLayout>
        <div class="mx-auto max-w-3xl">
            <AccountSettings v-if="isReady" panel="admin" />
        </div>
    </AdminLayout>
</template>

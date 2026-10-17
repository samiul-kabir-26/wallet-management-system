<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import UserLayout from '@/layouts/UserLayout.vue';
import AccountSettings from '@/components/Account/AccountSettings.vue';

const { isReady } = useAuthGuard('USER');
</script>

<template>
    <Head title="My Account" />
    <UserLayout>
        <div class="mx-auto max-w-3xl">
            <AccountSettings v-if="isReady" panel="user" />
        </div>
    </UserLayout>
</template>

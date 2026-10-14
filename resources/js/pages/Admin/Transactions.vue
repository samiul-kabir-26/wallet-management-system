<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import AdminLayout from '@/layouts/AdminLayout.vue';
import TransactionLedger from '@/components/Admin/TransactionLedger.vue';

useAuthGuard('ADMIN', 'transactions.view_any');
</script>

<template>
    <Head title="Platform Transaction Ledger - WalletMS Admin" />
    <AdminLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                    Platform Transaction Ledger
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Global real-time audit ledger for all movements across the wallet ecosystem
                </p>
            </div>

            <TransactionLedger />
        </div>
    </AdminLayout>
</template>

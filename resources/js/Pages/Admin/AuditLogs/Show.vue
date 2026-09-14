<script setup>
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    log: { type: Object, required: true },
    actionLabel: { type: String, required: true },
});

// Badge colors keyed by the enum value — accepted hardcode level for badges.
const badgeClass = (value) => ({
    UserRoleChanged: 'bg-blue-100 text-blue-800',
    UserBlocked: 'bg-red-100 text-red-800',
    UserUnblocked: 'bg-green-100 text-green-800',
}[value] ?? 'bg-gray-100 text-gray-800');

const formatDateTime = (value) => (value ? new Date(value).toLocaleString('ru-RU') : '—');
</script>

<template>
    <AdminLayout>
        <div class="mb-6">
            <Link href="/admin/audit-logs" class="text-sm text-gray-500 hover:text-gray-700">
                ← К списку
            </Link>
        </div>
        <h1 class="text-2xl font-semibold text-gray-900 mb-6">Запись аудита #{{ log.id }}</h1>

        <div class="bg-white rounded-lg border border-gray-200 p-6 space-y-4">
            <div>
                <p class="text-sm text-gray-500">Дата/время</p>
                <p class="text-base text-gray-900">{{ formatDateTime(log.created_at) }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Администратор</p>
                <p class="text-base text-gray-900">{{ log.admin?.email ?? `#${log.admin_id}` }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Действие</p>
                <p class="text-base text-gray-900">
                    <span class="px-2 py-1 rounded text-xs" :class="badgeClass(log.action)">
                        {{ actionLabel }}
                    </span>
                </p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Объект</p>
                <p class="text-base text-gray-900">
                    <template v-if="log.subject_type">{{ log.subject_type }} #{{ log.subject_id }}</template>
                    <template v-else>—</template>
                </p>
            </div>
            <div>
                <p class="text-sm text-gray-500">IP</p>
                <p class="text-base text-gray-900">{{ log.ip ?? '—' }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">User-Agent</p>
                <p class="text-base text-gray-900 break-all">{{ log.user_agent ?? '—' }}</p>
            </div>
        </div>

        <div class="mt-6 bg-white rounded-lg border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Meta</h2>
            <pre
                v-if="log.meta"
                class="bg-gray-50 rounded-md p-4 text-sm text-gray-800 overflow-x-auto"
            >{{ JSON.stringify(log.meta, null, 2) }}</pre>
            <p v-else class="text-sm text-gray-500">—</p>
        </div>
    </AdminLayout>
</template>

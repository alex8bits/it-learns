<script setup>
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const applyFilter = (key, value) => {
    const next = { ...props.filters, [key]: value || undefined };
    router.get('/admin/audit-logs', next, { preserveState: true, replace: true });
};
</script>

<template>
    <AdminLayout>
        <h1 class="text-2xl font-semibold text-gray-900 mb-6">Аудит-лог</h1>
        <div class="bg-white rounded-lg border border-gray-200">
            <div class="p-4 border-b border-gray-200 flex gap-3">
                <select
                    :value="filters.action ?? ''"
                    @change="applyFilter('action', $event.target.value)"
                    class="px-3 py-2 border border-gray-300 rounded-md"
                >
                    <option value="">Все действия</option>
                    <option value="UserRoleChanged">Смена роли</option>
                    <option value="UserBlocked">Блокировка</option>
                    <option value="UserUnblocked">Разблокировка</option>
                </select>
                <input
                    :value="filters.admin_id ?? ''"
                    type="number"
                    min="1"
                    placeholder="ID админа"
                    @change="applyFilter('admin_id', $event.target.value)"
                    class="px-3 py-2 border border-gray-300 rounded-md"
                />
            </div>
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Время</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Админ</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Действие</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Объект</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="log in logs.data"
                        :key="log.id"
                        class="border-t border-gray-200"
                    >
                        <td class="px-4 py-2 text-sm">
                            {{ new Date(log.created_at).toLocaleString('ru-RU') }}
                        </td>
                        <td class="px-4 py-2 text-sm">
                            {{ log.admin?.name ?? `#${log.admin_id}` }}
                        </td>
                        <td class="px-4 py-2 text-sm">
                            <span class="px-2 py-1 bg-gray-100 rounded text-xs">{{ log.action }}</span>
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-500">
                            {{ log.subject_type }} #{{ log.subject_id }}
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-500">{{ log.ip ?? '—' }}</td>
                    </tr>
                    <tr v-if="!logs.data || logs.data.length === 0">
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">
                            Записи отсутствуют
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>

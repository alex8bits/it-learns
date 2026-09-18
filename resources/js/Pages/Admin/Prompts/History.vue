<script setup>
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { Link, router, usePage } from '@inertiajs/vue3';

defineProps({
    versions: { type: Array, default: () => [] },
    promptKey: { type: String, required: true },
});

const page = usePage();

const formatDateTime = (value) => (value ? new Date(value).toLocaleString('ru-RU') : '—');

const rollback = (version) => {
    if (confirm(`Сделать версию v${version.version_number} активной?`)) {
        router.post(`/admin/prompts/history/${version.id}/rollback`);
    }
};
</script>

<template>
    <AdminLayout>
        <div class="mb-6">
            <Link href="/admin/prompts" class="text-sm text-gray-500 hover:text-gray-700">
                ← К редактору промпта
            </Link>
        </div>
        <h1 class="text-2xl font-semibold text-gray-900 mb-2">История версий промпта</h1>
        <p class="text-sm text-gray-500 mb-6">{{ promptKey }}</p>
        <p
            v-if="page.props.status"
            class="mb-4 px-4 py-3 bg-green-50 text-green-800 rounded-md text-sm"
        >
            {{ page.props.status }}
        </p>
        <div class="bg-white rounded-lg border border-gray-200">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Версия</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Автор</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Дата</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Комментарий</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Текст</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="version in versions"
                        :key="version.id"
                        class="border-t border-gray-200"
                    >
                        <td class="px-4 py-2 text-sm font-medium text-gray-900">
                            v{{ version.version_number }}
                        </td>
                        <td class="px-4 py-2 text-sm">
                            {{ version.author?.name ?? 'Система' }}
                        </td>
                        <td class="px-4 py-2 text-sm">
                            {{ formatDateTime(version.created_at) }}
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-500">
                            {{ version.comment ?? '—' }}
                        </td>
                        <td class="px-4 py-2 text-sm">
                            <details>
                                <summary class="cursor-pointer text-gray-600 text-xs">
                                    Показать текст
                                </summary>
                                <pre class="mt-2 p-3 bg-gray-50 border border-gray-200 rounded font-mono text-xs whitespace-pre-wrap">{{ version.body }}</pre>
                            </details>
                        </td>
                        <td class="px-4 py-2 text-sm">
                            <button
                                type="button"
                                class="px-3 py-1.5 bg-gray-900 text-white rounded-md"
                                @click="rollback(version)"
                            >
                                Сделать активной
                            </button>
                        </td>
                    </tr>
                    <tr v-if="versions.length === 0">
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">
                            Версий пока нет
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>

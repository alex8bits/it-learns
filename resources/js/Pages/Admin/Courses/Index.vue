<script setup>
import Pagination from '../../../Components/Pagination.vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    courses: { type: Object, required: true },
    statuses: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
});

const page = usePage();

// Local select state seeded from the `filters` prop: props must not be
// mutated via v-model, and the backend echo keeps a shared/refreshed
// link in sync (the same approach as the admin users list).
const statusFilter = ref(props.filters.status ?? '');

const applyFilter = () => {
    router.get(
        '/admin/courses',
        { status: statusFilter.value || undefined },
        { preserveState: true, replace: true },
    );
};

// Labels come from the backend option props — no enum duplicates in JS.
const labelFrom = (options, value) => options.find((o) => o.value === value)?.label ?? value;

const statusBadgeClass = (value) => ({
    Draft: 'bg-gray-100 text-gray-800',
    Published: 'bg-green-100 text-green-800',
    Archived: 'bg-yellow-100 text-yellow-800',
}[value] ?? 'bg-gray-100 text-gray-800');

const formatDate = (value) => (value ? new Date(value).toLocaleDateString('ru-RU') : '—');
</script>

<template>
    <AdminLayout>
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-semibold text-gray-900">Курсы</h1>
            <Link
                href="/admin/courses/create"
                class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm"
            >
                Создать курс
            </Link>
        </div>
        <p
            v-if="page.props.status"
            class="mb-4 px-4 py-3 bg-green-50 text-green-800 rounded-md text-sm"
        >
            {{ page.props.status }}
        </p>
        <div class="bg-white rounded-lg border border-gray-200">
            <div class="p-4 border-b border-gray-200">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    Статус
                    <select
                        v-model="statusFilter"
                        class="px-3 py-2 border border-gray-300 rounded-md"
                        @change="applyFilter"
                    >
                        <option value="">Все</option>
                        <option
                            v-for="option in statuses"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>
            </div>
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Превью</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Название</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Slug</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Статус</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Порядок</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Уровней</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Создан</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="course in courses.data" :key="course.id" class="border-t border-gray-200">
                        <td class="px-4 py-2">
                            <img
                                v-if="course.preview_image_url"
                                :src="course.preview_image_url"
                                :alt="course.title"
                                class="h-10 w-10 rounded object-cover"
                            >
                            <div
                                v-else
                                class="h-10 w-10 rounded bg-gradient-to-br from-blue-500 to-indigo-600"
                            />
                        </td>
                        <td class="px-4 py-2 text-sm">
                            <Link
                                :href="`/admin/courses/${course.id}`"
                                class="text-blue-600 hover:underline"
                            >
                                {{ course.title }}
                            </Link>
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-500">{{ course.slug }}</td>
                        <td class="px-4 py-2 text-sm">
                            <span
                                class="px-2 py-1 rounded text-xs"
                                :class="statusBadgeClass(course.status)"
                            >
                                {{ labelFrom(statuses, course.status) }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-500">{{ course.sort_order }}</td>
                        <td class="px-4 py-2 text-sm">{{ course.levels_count }}</td>
                        <td class="px-4 py-2 text-sm text-gray-500">
                            {{ formatDate(course.created_at) }}
                        </td>
                        <td class="px-4 py-2 text-sm text-right">
                            <Link
                                :href="`/admin/courses/${course.id}/prompt`"
                                class="text-blue-600 hover:text-blue-800"
                            >
                                Промпт
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="!courses.data || courses.data.length === 0">
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500">
                            Курсы не найдены
                        </td>
                    </tr>
                </tbody>
            </table>
            <Pagination :paginator="courses" />
        </div>
    </AdminLayout>
</template>

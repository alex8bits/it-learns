<script setup>
import Pagination from '../../../Components/Pagination.vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

const props = defineProps({
    users: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

// Local input state: props.filters must not be mutated via v-model.
const email = ref(props.filters.email ?? '');

let searchTimer = null;

const applySearch = () => {
    router.get(
        '/admin/users',
        {
            email: email.value.trim() || undefined,
            role: props.filters.role || undefined,
        },
        { preserveState: true, replace: true },
    );
};

// Debounce the search so we do not hit the server on every keystroke.
const onSearchInput = () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applySearch, 350);
};

onBeforeUnmount(() => clearTimeout(searchTimer));
</script>

<template>
    <AdminLayout>
        <h1 class="text-2xl font-semibold text-gray-900 mb-6">Пользователи</h1>
        <div class="bg-white rounded-lg border border-gray-200">
            <div class="p-4 border-b border-gray-200">
                <input
                    v-model="email"
                    type="search"
                    placeholder="Поиск по email"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md"
                    @input="onSearchInput"
                />
            </div>
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">ID</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Email</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Имя</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Роль</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Статус</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Создан</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users.data" :key="user.id" class="border-t border-gray-200">
                        <td class="px-4 py-2 text-sm">{{ user.id }}</td>
                        <td class="px-4 py-2 text-sm">
                            <Link :href="`/admin/users/${user.id}`" class="text-blue-600 hover:underline">
                                {{ user.email }}
                            </Link>
                        </td>
                        <td class="px-4 py-2 text-sm">{{ user.name }}</td>
                        <td class="px-4 py-2 text-sm">
                            <span
                                v-for="role in user.roles"
                                :key="role.id ?? role.name"
                                class="px-2 py-1 bg-gray-100 rounded text-xs mr-1"
                            >
                                {{ role.name }}
                            </span>
                            <span v-if="!user.roles || user.roles.length === 0" class="text-xs text-gray-400">—</span>
                        </td>
                        <td class="px-4 py-2 text-sm">
                            <span
                                v-if="user.is_blocked"
                                class="px-2 py-1 bg-red-100 text-red-800 rounded text-xs"
                            >Заблокирован</span>
                            <span
                                v-else
                                class="px-2 py-1 bg-green-100 text-green-800 rounded text-xs"
                            >Активен</span>
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-500">
                            {{ new Date(user.created_at).toLocaleDateString('ru-RU') }}
                        </td>
                    </tr>
                    <tr v-if="!users.data || users.data.length === 0">
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">
                            Пользователи не найдены
                        </td>
                    </tr>
                </tbody>
            </table>
            <Pagination :paginator="users" />
        </div>
    </AdminLayout>
</template>

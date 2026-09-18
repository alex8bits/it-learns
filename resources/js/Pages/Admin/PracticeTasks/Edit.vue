<script setup>
import Button from '../../../Components/Button.vue';
import Input from '../../../Components/Input.vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    // The controller loads `lesson.level`; the edit form submits every
    // task field (the server recomputes the expected hash from the rows).
    // `runtimeOptions` comes from PracticeRuntime::options() — the enum
    // cast serializes `task.runtime` as its string value.
    task: { type: Object, required: true },
    runtimeOptions: { type: Array, required: true },
});

const form = useForm({
    statement: props.task.statement,
    expected_result_text: props.task.expected_result_text,
    expected_rows: JSON.stringify(props.task.expected_rows ?? [], null, 2),
    seed_sql: props.task.seed_sql ?? '',
    order: props.task.order,
    is_published: props.task.is_published,
    runtime: props.task.runtime,
});

const submit = () => {
    // An empty order is dropped entirely so the backend keeps the current
    // order instead of casting an explicit null to 0.
    form.transform((data) => {
        const payload = { ...data };
        if (payload.order === '' || payload.order === null) {
            delete payload.order;
        }
        return payload;
    }).patch(`/admin/practice-tasks/${props.task.id}`);
};

// Check-the-reference-query block: local state only, deliberately NOT in
// useForm — it never travels to the update payload. Plain fetch (no axios
// in the project, no Inertia navigation — the form state survives).
const checkQuery = ref('');
const checking = ref(false);
const checkResult = ref(null);
const checkError = ref(null);

// Status-specific states win over the comparison chip: Busy is a lost
// lock race (nothing executed), Error means the query itself failed.
const checkChip = computed(() => {
    const result = checkResult.value;

    if (!result) {
        return null;
    }

    if (result.status === 'busy') {
        return { text: 'Предыдущая проверка ещё выполняется — попробуйте чуть позже', classes: 'bg-yellow-100 text-yellow-800' };
    }

    if (result.status === 'error') {
        return { text: 'Ошибка исполнения', classes: 'bg-red-100 text-red-800' };
    }

    if (result.matched === true) {
        return { text: 'Совпало с эталоном', classes: 'bg-green-100 text-green-800' };
    }

    if (result.matched === false) {
        return { text: 'Не совпало с эталоном', classes: 'bg-red-100 text-red-800' };
    }

    return { text: 'Без эталона — только результат', classes: 'bg-gray-100 text-gray-700' };
});

const checkColumns = computed(() => {
    const result = checkResult.value?.result;

    if (!result) {
        return [];
    }

    if (Array.isArray(result.columns) && result.columns.length > 0) {
        return result.columns;
    }

    return Object.keys(result.rows?.[0] ?? {});
});

const runCheck = async () => {
    if (checking.value || !checkQuery.value.trim()) {
        return;
    }

    checking.value = true;
    checkError.value = null;

    try {
        const response = await fetch('/admin/practice-tasks/check', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                code: checkQuery.value,
                seed_sql: form.seed_sql || null,
                expected_rows: form.expected_rows || null,
            }),
        });

        const payload = await response.json().catch(() => null);

        if (response.ok) {
            checkResult.value = payload;

            return;
        }

        const fieldErrors = payload?.errors ? Object.values(payload.errors).flat() : [];
        checkError.value = payload?.message ?? fieldErrors[0] ?? 'Не удалось выполнить проверку';
    } catch {
        checkError.value = 'Не удалось связаться с сервером';
    } finally {
        checking.value = false;
    }
};

const useResultAsExpected = () => {
    const rows = checkResult.value?.result?.rows;

    if (!Array.isArray(rows) || rows.length === 0) {
        return;
    }

    form.expected_rows = JSON.stringify(rows, null, 2);
    form.clearErrors('expected_rows');
};
</script>

<template>
    <AdminLayout>
        <div class="mb-6">
            <Link
                :href="`/admin/lessons/${task.lesson.id}/edit`"
                class="text-sm text-gray-500 hover:text-gray-700"
            >
                ← К уроку
            </Link>
        </div>
        <h1 class="text-2xl font-semibold text-gray-900 mb-2">Редактирование практического задания</h1>
        <p class="text-sm text-gray-500 mb-6">Урок: {{ task.lesson.title }}</p>
        <form
            @submit.prevent="submit"
            class="bg-white rounded-lg border border-gray-200 p-6 max-w-3xl"
        >
            <label class="block mb-3">
                <span class="text-sm text-gray-700">Условие задачи</span>
                <textarea
                    v-model="form.statement"
                    rows="4"
                    maxlength="8192"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md text-sm"
                    :class="form.errors.statement ? 'border-red-400' : ''"
                ></textarea>
            </label>
            <p v-if="form.errors.statement" class="mb-3 text-xs text-red-600 break-words">
                {{ form.errors.statement }}
            </p>

            <label class="block mb-3">
                <span class="text-sm text-gray-700">Ожидаемый результат</span>
                <textarea
                    v-model="form.expected_result_text"
                    rows="3"
                    maxlength="8192"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md text-sm"
                    :class="form.errors.expected_result_text ? 'border-red-400' : ''"
                ></textarea>
            </label>
            <p v-if="form.errors.expected_result_text" class="mb-3 text-xs text-red-600 break-words">
                {{ form.errors.expected_result_text }}
            </p>

            <label class="block mb-3">
                <span class="text-sm text-gray-700">Эталонные строки (JSON)</span>
                <textarea
                    v-model="form.expected_rows"
                    rows="6"
                    maxlength="65535"
                    placeholder='[{"id": 1, "title": "SQL Basics"}]'
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md font-mono text-sm"
                    :class="form.errors.expected_rows ? 'border-red-400' : ''"
                ></textarea>
            </label>
            <p class="-mt-2 mb-3 text-xs text-gray-500">
                JSON-массив строк-объектов с одинаковым набором ключей; эталонный хеш вычисляется на сервере
            </p>
            <p v-if="form.errors.expected_rows" class="mb-3 text-xs text-red-600 break-words">
                {{ form.errors.expected_rows }}
            </p>

            <label class="block mb-3">
                <span class="text-sm text-gray-700">Скрипт наполнения (необязательно)</span>
                <textarea
                    v-model="form.seed_sql"
                    rows="6"
                    maxlength="65535"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md font-mono text-sm"
                    :class="form.errors.seed_sql ? 'border-red-400' : ''"
                ></textarea>
            </label>
            <p v-if="form.errors.seed_sql" class="mb-3 text-xs text-red-600 break-words">
                {{ form.errors.seed_sql }}
            </p>

            <Input
                v-model="form.order"
                label="Порядок"
                name="order"
                type="number"
                :error="form.errors.order"
            />
            <label class="block mb-3">
                <span class="text-sm text-gray-700">Рантайм</span>
                <select
                    v-model="form.runtime"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md"
                    :class="form.errors.runtime ? 'border-red-400' : ''"
                >
                    <option
                        v-for="option in runtimeOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </label>
            <p class="-mt-2 mb-3 text-xs text-gray-500">
                MySQL и PostgreSQL требуют драйвер docker (PRACTICE_DRIVER=docker); SQLite — local-sqlite
            </p>
            <p v-if="form.errors.runtime" class="mb-3 text-xs text-red-600 break-words">
                {{ form.errors.runtime }}
            </p>
            <label class="flex items-center gap-2 mb-3">
                <input
                    v-model="form.is_published"
                    type="checkbox"
                    class="rounded border-gray-300"
                >
                <span class="text-sm text-gray-700">Опубликован</span>
            </label>

            <div class="mt-4">
                <Button type="submit" :processing="form.processing">Сохранить</Button>
            </div>
        </form>

        <div class="bg-white rounded-lg border border-gray-200 p-6 max-w-3xl mt-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-1">Проверка задания</h2>
            <p class="text-sm text-gray-500 mb-4">
                Запрос выполняется против скрипта наполнения в изолированной среде; форма не сбрасывается
            </p>

            <label class="block mb-3">
                <span class="text-sm text-gray-700">Эталонный запрос (для проверки)</span>
                <textarea
                    v-model="checkQuery"
                    rows="3"
                    maxlength="65535"
                    placeholder="SELECT ..."
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md font-mono text-sm"
                ></textarea>
            </label>

            <div class="mb-3">
                <Button
                    type="button"
                    :disabled="checking || !checkQuery.trim()"
                    :processing="checking"
                    @click="runCheck"
                >
                    Проверить запрос
                </Button>
            </div>

            <p v-if="checkError" class="mb-3 text-sm text-red-600 break-words">
                {{ checkError }}
            </p>

            <div v-if="checkResult" class="border-t border-gray-200 pt-4">
                <span
                    v-if="checkChip"
                    class="inline-block px-3 py-1 rounded-full text-xs font-medium mb-3"
                    :class="checkChip.classes"
                >
                    {{ checkChip.text }}
                </span>

                <p v-if="checkResult.result?.error" class="mb-2 text-sm text-red-600 break-words">
                    {{ checkResult.result.error }}
                </p>
                <p v-if="checkResult.result" class="mb-3 text-xs text-gray-500">
                    Время выполнения: {{ Math.round(checkResult.result.duration_ms) }} мс
                </p>

                <div v-if="checkResult.result?.rows?.length" class="overflow-x-auto mb-3">
                    <table class="min-w-full text-sm border border-gray-200">
                        <thead>
                            <tr class="bg-gray-50 text-left">
                                <th
                                    v-for="column in checkColumns"
                                    :key="column"
                                    class="px-3 py-2 border-b border-gray-200 font-medium"
                                >
                                    {{ column }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in checkResult.result.rows" :key="index" class="align-top">
                                <td v-for="column in checkColumns" :key="column" class="px-3 py-2 border-b border-gray-100">
                                    {{ row[column] }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else-if="checkResult.result && !checkResult.result.error" class="mb-3 text-sm text-gray-500">
                    Запрос не вернул строк
                </p>

                <Button
                    v-if="checkResult.result?.rows?.length"
                    type="button"
                    variant="secondary"
                    @click="useResultAsExpected"
                >
                    Использовать как эталон
                </Button>
            </div>
        </div>
    </AdminLayout>
</template>

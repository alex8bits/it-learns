<template>
    <div class="space-y-4">
        <div class="bg-gray-900 text-gray-100 font-mono text-sm rounded-md p-4 max-h-96 overflow-y-auto">
            <p v-if="entries.length === 0" class="text-gray-500">
                Здесь появится результат вашего запроса.
            </p>

            <div v-for="entry in entries" v-else :key="entry.id" class="mb-4 last:mb-0">
                <p class="text-green-400 whitespace-pre-wrap break-words">&gt; {{ entry.code }}</p>

                <div v-if="entry.status === 'error'" class="mt-1 text-red-400 whitespace-pre-line">
                    Ошибка выполнения: {{ entry.error_text }}
                </div>

                <template v-else-if="entry.status === 'passed' || entry.status === 'failed'">
                    <div v-if="entry.result?.error" class="mt-1 text-red-400 whitespace-pre-line">
                        {{ entry.result.error }}
                    </div>

                    <table
                        v-if="Array.isArray(entry.result?.rows) && entry.result.rows.length > 0"
                        class="mt-2 w-full text-left text-xs"
                    >
                        <thead class="bg-gray-800 text-gray-300">
                            <tr>
                                <th
                                    v-for="column in resultColumns(entry)"
                                    :key="column"
                                    class="border-b border-gray-700 px-2 py-1 font-medium"
                                >
                                    {{ column }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, rowIndex) in visibleRows(entry)"
                                :key="rowIndex"
                                class="text-gray-100"
                            >
                                <td
                                    v-for="column in resultColumns(entry)"
                                    :key="column"
                                    class="border-b border-gray-800 px-2 py-1"
                                >
                                    {{ row[column] }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p
                        v-else-if="Array.isArray(entry.result?.rows) && entry.result.rows.length === 0"
                        class="mt-1 text-gray-400"
                    >
                        OK (0 строк)
                    </p>
                    <p
                        v-else-if="!entry.result?.error"
                        class="mt-1 text-gray-400"
                    >
                        OK
                    </p>

                    <p
                        v-if="entry.result?.rows && entry.result.rows.length > rowLimit"
                        class="mt-1 text-xs text-gray-500"
                    >
                        Показаны первые {{ rowLimit }} из {{ entry.result.rows.length }} строк.
                    </p>

                    <!-- Diff приходит при passed и failed (при error/busy —
                         null), поэтому рендер ведём по его наличию: таблицы
                         «Эталон»/«Ваш результат» показываются для обеих
                         статусных попыток с diff. -->
                    <div
                        v-if="entry.diff && diffSides(entry).length > 0"
                        class="mt-3 grid gap-4 md:grid-cols-2"
                    >
                        <div
                            v-for="side in diffSides(entry)"
                            :key="side.title"
                            class="bg-gray-800 rounded p-2"
                        >
                            <p class="font-medium text-gray-200 mb-1">{{ side.title }}</p>
                            <table
                                v-if="side.rows.length > 0"
                                class="w-full text-left text-xs"
                            >
                                <thead class="bg-gray-700 text-gray-200">
                                    <tr>
                                        <th
                                            v-for="column in diffColumns(side.rows)"
                                            :key="column"
                                            class="border-b border-gray-600 px-2 py-1 font-medium"
                                        >
                                            {{ column }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(row, rowIndex) in side.rows"
                                        :key="rowIndex"
                                        class="text-gray-100"
                                    >
                                        <td
                                            v-for="column in diffColumns(side.rows)"
                                            :key="column"
                                            class="border-b border-gray-700 px-2 py-1"
                                        >
                                            {{ row[column] }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-else class="text-xs text-gray-500">Запрос не вернул строк</p>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <form v-if="!disabled" @submit.prevent="emit('submit')">
            <label class="block mb-3">
                <span class="text-sm text-gray-700">SQL-запрос</span>
                <textarea
                    :value="code"
                    @input="emit('update:code', $event.target.value)"
                    rows="4"
                    placeholder="SELECT ..."
                    class="mt-1 w-full px-3 py-2 border rounded-md font-mono text-sm bg-gray-50 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-400"
                    :class="codeError ? 'border-red-400' : 'border-gray-300'"
                    :disabled="processing"
                ></textarea>
            </label>
            <p v-if="codeError" class="mb-3 text-xs text-red-600 break-words">{{ codeError }}</p>
            <Button type="submit" :processing="processing">Выполнить</Button>
        </form>
    </div>
</template>

<script setup>
import Button from './Button.vue';

const props = defineProps({
    entries: { type: Array, required: false, default: () => [] },
    code: { type: String, required: true },
    processing: { type: Boolean, required: false, default: false },
    codeError: { type: String, required: false, default: null },
    disabled: { type: Boolean, required: false, default: false },
});

const emit = defineEmits(['update:code', 'submit']);

// Лимит отображаемых строк результата: защита рендера от очень больших rows
// (фронтенд-митигация, см. design «Risks and mitigations»).
const rowLimit = 200;

const resultColumns = (entry) => {
    const rows = entry?.result?.rows;
    return Array.isArray(rows) && rows.length > 0 ? Object.keys(rows[0]) : [];
};

const visibleRows = (entry) => {
    const rows = entry?.result?.rows;
    return Array.isArray(rows) ? rows.slice(0, rowLimit) : [];
};

// Колонки таблицы диффа — ключи первой строки результата (перенос логики
// из Show.vue:160, адаптированный под per-entry вызов).
const diffColumns = (rows) => (Array.isArray(rows) && rows.length > 0 ? Object.keys(rows[0]) : []);

const diffSides = (entry) => {
    if (!entry?.diff) {
        return [];
    }

    return [
        { title: 'Эталон', rows: entry.diff.expected ?? [] },
        { title: 'Ваш результат', rows: entry.diff.actual ?? [] },
    ];
};
</script>

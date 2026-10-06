// Клиентские черновики SQL-решений практики: зеркальная копия содержимого
// редактора (Show.vue), переживающая перезагрузку страницы и раундтрип
// «истёкшая сессия → логин → возврат на урок». Ключ —
// `practice-draft:{userId}:{taskId}` (изоляция по пользователю и заданию),
// значение — сырая строка SQL. localStorage может быть недоступен
// (private mode, заполненная квота): любая операция тихо деградирует,
// редактор продолжает работать без черновиков.
const draftKey = (userId, taskId) => `practice-draft:${userId}:${taskId}`;

export const loadPracticeDraft = (userId, taskId) => {
    // null/undefined части ключа — нет черновика (гость, задание не выбрано).
    if (userId == null || taskId == null) {
        return '';
    }

    try {
        return localStorage.getItem(draftKey(userId, taskId)) ?? '';
    } catch {
        return '';
    }
};

export const savePracticeDraft = (userId, taskId, code) => {
    if (userId == null || taskId == null) {
        return;
    }

    try {
        // Пустая строка удаляет ключ: ручная очистка поля «прилипает»,
        // а не воскресает после перезагрузки страницы.
        if (code === '') {
            localStorage.removeItem(draftKey(userId, taskId));
            return;
        }

        localStorage.setItem(draftKey(userId, taskId), code);
    } catch {
        // Тихий отказ: недоступный storage не должен ломать редактор.
    }
};

export const clearPracticeDraft = (userId, taskId) => savePracticeDraft(userId, taskId, '');

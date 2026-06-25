import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError, httpJson } from "../http/client";

/**
 * Состояние запроса
 */
type State<T> = {
    isLoading: boolean;
    data: T | null;
    error: string | null;
};

/**
 * Параметры запроса
 */
type RunOptions = {
    url: string;
    query?: Record<string, any>;
    csrfToken?: string | null;
};

/**
 * Hook для запросов с автоматическим abort и обработкой race conditions
 *
 * Автоматически:
 * - Отменяет предыдущий запрос при новом вызове run()
 * - Очищает запросы при unmount компонента
 * - Обрабатывает ошибки в user-friendly формате
 *
 * @example
 * const { isLoading, data, error, run } = useAbortableQuery<UserDto>();
 *
 * useEffect(() => {
 *   void run({ url: '/api/users', query: { page: 1 } });
 * }, [page]);
 *
 * if (isLoading) return <Spinner />;
 * if (error) return <Error message={error} />;
 * return <UserList users={data} />;
 */
export function useAbortableQuery<T>() {
    const abortRef = useRef<AbortController | null>(null);

    const [state, setState] = useState<State<T>>({
        isLoading: false,
        data: null,
        error: null,
    });

    /**
     * Выполнить запрос с автоматическим abort предыдущего
     */
    const run = useCallback(async (opts: RunOptions): Promise<T | null> => {
        // Отменяем предыдущий запрос (если есть)
        abortRef.current?.abort();

        // Создаём новый AbortController
        const ac = new AbortController();
        abortRef.current = ac;

        // Устанавливаем состояние загрузки
        setState((prev) => ({ ...prev, isLoading: true, error: null }));

        try {
            const data = await httpJson<T>(opts.url, {
                method: "GET",
                query: opts.query,
                signal: ac.signal,
                csrfToken: opts.csrfToken ?? null,
            });

            // Проверяем что запрос не был отменён
            if (!ac.signal.aborted) {
                setState({ isLoading: false, data, error: null });
            }

            return data;
        } catch (e: any) {
            // Если запрос отменён - не считаем ошибкой
            if (e?.name === "AbortError") {
                return null;
            }

            // Получаем user-friendly сообщение
            const message =
                e instanceof ApiError
                    ? e.message
                    : "Не удалось загрузить данные. Повторите попытку.";

            // Проверяем что запрос не был отменён
            if (!ac.signal.aborted) {
                setState((prev) => ({ ...prev, isLoading: false, error: message }));
            }

            return null;
        }
    }, []);

    /**
     * Cleanup: отменяем запрос при unmount компонента
     */
    useEffect(() => {
        return () => {
            abortRef.current?.abort();
        };
    }, []);

    return {
        isLoading: state.isLoading,
        data: state.data,
        error: state.error,
        run,
    };
}

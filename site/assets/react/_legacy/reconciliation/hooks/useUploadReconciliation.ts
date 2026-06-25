import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError } from "../../shared/http/client";
import type { ReconciliationSession, ReconciliationUploadResponse } from "../reconciliation.types";

interface UseUploadReconciliationResult {
    isLoading: boolean;
    error: string | null;
    session: ReconciliationSession | null;
    upload: (file: File, periodFrom: string, periodTo: string, marketplace?: string) => void;
    reset: () => void;
}

/**
 * Mutation-хук: загрузить xlsx и получить результат сверки.
 *
 * POST /api/marketplace/reconciliation/upload (multipart/form-data)
 *
 * httpJson не подходит для FormData (он ставит Content-Type: application/json),
 * поэтому используем raw fetch с тем же паттерном abort + state.
 */
export function useUploadReconciliation(): UseUploadReconciliationResult {
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [session, setSession] = useState<ReconciliationSession | null>(null);
    const abortRef = useRef<AbortController | null>(null);

    const upload = useCallback(
        (file: File, periodFrom: string, periodTo: string, marketplace = "ozon") => {
            abortRef.current?.abort();
            const ac = new AbortController();
            abortRef.current = ac;

            setIsLoading(true);
            setError(null);
            setSession(null);

            const formData = new FormData();
            formData.append("file", file);
            formData.append("periodFrom", periodFrom);
            formData.append("periodTo", periodTo);
            formData.append("marketplace", marketplace);

            fetch("/api/marketplace/reconciliation/upload", {
                method: "POST",
                body: formData,
                credentials: "same-origin",
                signal: ac.signal,
            })
                .then(async (res) => {
                    const payload = await res.json() as ReconciliationUploadResponse & { error?: string };

                    if (!res.ok) {
                        throw new ApiError(
                            payload.error ?? payload.errorMessage ?? `HTTP ${res.status}`,
                            res.status === 422 ? "validation" : "server",
                            res.status,
                            payload,
                        );
                    }

                    return payload;
                })
                .then((data) => {
                    if (ac.signal.aborted) return;

                    // Формируем полную ReconciliationSession из ответа upload
                    const uploaded: ReconciliationSession = {
                        id: data.id,
                        marketplace,
                        periodFrom,
                        periodTo,
                        originalFilename: file.name,
                        status: data.status,
                        result: data.result ?? null,
                        errorMessage: data.errorMessage ?? null,
                        createdAt: new Date().toISOString(),
                    };

                    setSession(uploaded);
                    setIsLoading(false);

                    if (data.status === "failed") {
                        setError(data.errorMessage ?? "Сверка завершилась с ошибкой.");
                    }
                })
                .catch((e: unknown) => {
                    if (e instanceof Error && e.name === "AbortError") return;

                    const message =
                        e instanceof ApiError
                            ? e.message
                            : "Не удалось загрузить файл. Повторите попытку.";

                    if (!ac.signal.aborted) {
                        setError(message);
                        setIsLoading(false);
                    }
                });
        },
        [],
    );

    const reset = useCallback(() => {
        setSession(null);
        setError(null);
    }, []);

    useEffect(() => {
        return () => {
            abortRef.current?.abort();
        };
    }, []);

    return { isLoading, error, session, upload, reset };
}

/**
 * Типы для модуля сверки маркетплейса (reconciliation)
 *
 * Структура соответствует ответу CostReconciliationQuery::reconcile()
 */

/** Сверка одной serviceGroup (из поля group_comparison) */
export interface GroupComparison {
    serviceGroup: string;
    apiAmount: number;
    xlsAmount: number;
    delta: number;
    status: "matched" | "mismatch";
    apiCategories: string[];
}

/** Полный результат reconcile() — поле result в ответе API */
export interface ReconcileResult {
    status: "matched" | "mismatch";
    api_net_amount: number;
    api_costs_amount: number;
    api_storno_amount: number;
    return_revenue: number;
    xlsx_comparable: number;
    xlsx_total: number;
    delta: number;
    xlsx_period: string;
    xlsx_lines_count: number;
    group_comparison: GroupComparison[];
}

/** Сессия сверки (ответ API GET /api/marketplace/reconciliation/{id}) */
export interface ReconciliationSession {
    id: string;
    marketplace: string;
    periodFrom: string;
    periodTo: string;
    originalFilename: string;
    status: "pending" | "completed" | "failed";
    result: ReconcileResult | null;
    errorMessage?: string | null;
    createdAt: string;
}

/** Ответ POST /api/marketplace/reconciliation/upload */
export interface ReconciliationUploadResponse {
    id: string;
    status: "completed" | "failed";
    result?: ReconcileResult | null;
    errorMessage?: string | null;
}

/** Элемент списка (ответ API /history — без поля result) */
export interface ReconciliationHistoryItem {
    id: string;
    marketplace: string;
    periodFrom: string;
    periodTo: string;
    originalFilename: string;
    status: "pending" | "completed" | "failed";
    createdAt: string;
}

/** Список сессий с пагинацией (ответ API /history) */
export interface ReconciliationHistoryResponse {
    items: ReconciliationHistoryItem[];
    total: number;
    page: number;
    limit: number;
}

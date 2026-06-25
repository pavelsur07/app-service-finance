/**
 * Типы для модуля сверки маркетплейса (reconciliation)
 *
 * Структура соответствует ответу CostReconciliationQuery::reconcile()
 */

/** Сверка одной serviceGroup (из поля group_comparison) */
export interface GroupComparison {
    service_group: string;
    api_net: number;
    xlsx_net: number;
    api_costs: number;
    api_storno: number;
    delta: number;
    status: "matched" | "mismatch";
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

/**
 * TEMPORARY: типы для debug-эндпоинта category-breakdown.
 * Удалить после завершения отладки сверки.
 */

/** Одна категория из debug category-breakdown */
export interface CategoryBreakdownItem {
    category_code: string;
    category_name: string;
    service_group: string | null;
    net_amount: number;
    costs_amount: number;
    storno_amount: number;
}

/** Группа услуг с вложенными категориями */
export interface ServiceGroupBreakdown {
    service_group: string;
    net_amount: number;
    costs_amount: number;
    storno_amount: number;
    categories: CategoryBreakdownItem[];
}

/** Ответ POST /api/marketplace/reconciliation/debug/category-breakdown */
export interface CategoryBreakdownResponse {
    categories: CategoryBreakdownItem[];
    by_service_group: ServiceGroupBreakdown[];
    unmapped: CategoryBreakdownItem[];
}

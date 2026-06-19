import type { operations } from '../../api/schema';

type JsonResponse<K extends keyof operations> =
    operations[K] extends { responses: { 200: infer SuccessResponse } }
        ? SuccessResponse extends { content: { 'application/json': infer Response } }
            ? NonNullable<Response>
            : never
        : never;

type QueryParameters<K extends keyof operations> =
    operations[K]['parameters'] extends { query: infer Query }
        ? Query
        : operations[K]['parameters'] extends { query?: infer Query }
            ? NonNullable<Query>
            : never;

export type CoverageResponse = JsonResponse<'get_api_ingestion_verification_coverage'>;
export type CoverageQuery = QueryParameters<'get_api_ingestion_verification_coverage'>;
export type CoverageCell = NonNullable<CoverageResponse['cells']>[number];
export type ShopOption = NonNullable<CoverageResponse['shops']>[number];

export type ReconciliationResponse = JsonResponse<'get_api_ingestion_verification_reconciliation'>;
export type ReconciliationQuery = QueryParameters<'get_api_ingestion_verification_reconciliation'>;
export type ReconciliationSummaryDto = NonNullable<ReconciliationResponse['summary']>;
export type ReconciliationByTypeDto = NonNullable<ReconciliationResponse['by_type']>[number];

export type IssuesResponse = JsonResponse<'get_api_ingestion_verification_issues'>;
export type IssuesQuery = QueryParameters<'get_api_ingestion_verification_issues'>;
export type IssueListItemDto = NonNullable<IssuesResponse['items']>[number];
export type IssuesMetaDto = NonNullable<IssuesResponse['meta']>;

export type FinancialSummaryResponse = JsonResponse<'get_api_ingestion_verification_financial_summary'>;
export type FinancialSummaryQuery = QueryParameters<'get_api_ingestion_verification_financial_summary'>;
export type FinancialSummaryMonthDto = NonNullable<FinancialSummaryResponse['by_month']>[number];
export type FinancialSummaryCategoryDto = NonNullable<FinancialSummaryResponse['by_category']>[number];

export interface MonthPeriod {
    year: number;
    month: number;
}

export interface DateRangePeriod {
    from: string;
    to: string;
}

export interface MonthRangePeriod {
    yearFrom: number;
    monthFrom: number;
    yearTo: number;
    monthTo: number;
}

export const BALANCE_PERIODS = [30, 60, 90] as const;

export type BalancePeriodDays = (typeof BALANCE_PERIODS)[number];
export type BalanceFlowKey = "operating" | "financing" | "investing";

export type BalanceDynamicsPoint = {
    date: string;
    balance: string;
    below_minimum: boolean;
    flows: Record<BalanceFlowKey, string>;
};

export type BalanceDynamicsResponse = {
    period: {
        days: BalancePeriodDays;
        from: string;
        to: string;
    };
    currency: string;
    minimum_balance: {
        amount: string;
        currency: string;
    } | null;
    points: BalanceDynamicsPoint[];
};

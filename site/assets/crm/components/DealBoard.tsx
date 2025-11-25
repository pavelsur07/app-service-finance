import React, { useEffect, useState } from 'react';
import axios from 'axios';

type Props = {
  pipelineId: string | null;
  filters: {
    assignee: string | 'all';
    channel: string | 'all';
    q: string;
    onlyWebForms: boolean;
    utmCampaign: string;
  };
  onOpenDeal: (deal: any) => void;
  reloadKey?: number;
};

type Deal = {
  id: string;
  title: string;
  stage?: string;
  assignee?: string;
  channel?: string;
  source?: string;
  client?: any;
  openedAt?: string;
  createdAt?: string;
};

export default function DealBoard({ pipelineId, filters, onOpenDeal, reloadKey }: Props) {
  const [deals, setDeals] = useState<Deal[]>([]);

  useEffect(() => {
    const params = {
      pipelineId,
      assignee: filters.assignee,
      channel: filters.channel,
      q: filters.q,
      onlyWebForms: filters.onlyWebForms,
      utmCampaign: filters.utmCampaign,
    };

    axios
      .get('/api/crm/deals', { params })
      .then((response) => {
        setDeals(response.data || []);
      })
      .catch(() => {
        setDeals([]);
      });
  }, [pipelineId, filters, reloadKey]);

  return (
    <div className="mt-4 grid grid-cols-1 gap-3">
      {deals.map((deal) => (
        <button
          key={deal.id}
          className="p-4 border border-gray-200 rounded-xl text-left hover:shadow"
          onClick={() => onOpenDeal(deal)}
          type="button"
        >
          <div className="text-sm font-medium text-gray-900">{deal.title}</div>
          {deal.stage && <div className="text-xs text-gray-500">Этап: {deal.stage}</div>}
        </button>
      ))}
      {!deals.length && <div className="text-sm text-gray-500">Сделки не найдены</div>}
    </div>
  );
}

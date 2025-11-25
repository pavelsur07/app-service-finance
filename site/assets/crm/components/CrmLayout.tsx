import React, { useState } from 'react';
import DealBoard from './DealBoard';
import FiltersBar from './FiltersBar';

type PipelineSummary = { id: string; title: string };
type Deal = { id: string; title: string };

type Filters = {
  assignee: string | 'all';
  channel: string | 'all';
  q: string;
  onlyWebForms: boolean;
  utmCampaign: string;
};

export default function CrmLayout() {
  const [activePipeline, setActivePipeline] = useState<PipelineSummary | null>(null);
  const [activeDeal, setActiveDeal] = useState<Deal | null>(null);
  const [filters, setFilters] = useState<{
    assignee: string | 'all';
    channel: string | 'all';
    q: string;
    onlyWebForms: boolean;
    utmCampaign: string;
  }>({
    assignee: 'all',
    channel: 'all',
    q: '',
    onlyWebForms: false,
    utmCampaign: '',
  });

  return (
    <div className="p-4">
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center gap-2">
          <button
            type="button"
            className="px-3 py-2 bg-gray-900 text-white rounded-lg"
            onClick={() => setActivePipeline({ id: 'default', title: 'Default' })}
          >
            Выбрать воронку
          </button>
        </div>
        <div className="flex items-center gap-2">
          {activeDeal && <span className="text-sm text-gray-600">Активная сделка: {activeDeal.title}</span>}
        </div>
      </div>

      <FiltersBar value={filters} onChange={setFilters} />

      <DealBoard pipelineId={activePipeline ? activePipeline.id : null} filters={filters} onOpenDeal={setActiveDeal} />
    </div>
  );
}

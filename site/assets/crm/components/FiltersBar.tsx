import React from 'react';

type Filters = {
  assignee: string | 'all';
  channel: string | 'all';
  q: string;
  onlyWebForms: boolean;
  utmCampaign: string;
};

export default function FiltersBar({ value, onChange }: { value: Filters; onChange: (v: Filters) => void }) {
  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-center gap-2 flex-wrap">
        <input
          value={value.assignee}
          onChange={(e) => onChange({ ...value, assignee: e.target.value as Filters['assignee'] })}
          className="w-full max-w-[180px] px-3 py-2 rounded-xl border border-gray-200 bg-white text-sm focus:outline-none focus:ring-2 focus:ring-gray-900"
          placeholder="Ответственный"
        />
        <input
          value={value.channel}
          onChange={(e) => onChange({ ...value, channel: e.target.value as Filters['channel'] })}
          className="w-full max-w-[180px] px-3 py-2 rounded-xl border border-gray-200 bg-white text-sm focus:outline-none focus:ring-2 focus:ring-gray-900"
          placeholder="Канал"
        />
        <input
          value={value.q}
          onChange={(e) => onChange({ ...value, q: e.target.value })}
          className="w-full max-w-xs px-3 py-2 rounded-xl border border-gray-200 bg-white text-sm focus:outline-none focus:ring-2 focus:ring-gray-900"
          placeholder="Поиск"
        />
        <input
          value={value.utmCampaign}
          onChange={(e) => onChange({ ...value, utmCampaign: e.target.value })}
          className="w-full max-w-xs px-3 py-2 rounded-xl border border-gray-200 bg-white text-sm focus:outline-none focus:ring-2 focus:ring-gray-900"
          placeholder="UTM-кампания"
        />
        <button
          type="button"
          onClick={() => onChange({ ...value, onlyWebForms: !value.onlyWebForms })}
          className={
            'px-3 py-1.5 rounded-xl text-xs border ' +
            (value.onlyWebForms
              ? 'bg-gray-900 text-white border-gray-900'
              : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-100')
          }
        >
          Только заявки с форм
        </button>
      </div>
    </div>
  );
}

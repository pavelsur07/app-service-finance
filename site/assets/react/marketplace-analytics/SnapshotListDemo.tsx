import { useEffect, useState } from 'react';
import { api } from '@/api/client';
import type { components } from '@/api/schema';

type SnapshotResponse = components['schemas']['SnapshotResponse'];
type PaginationMeta = components['schemas']['PaginationMeta'];

interface LoadedState {
    data: SnapshotResponse[];
    meta: PaginationMeta;
}

export default function SnapshotListDemo() {
    const [state, setState] = useState<LoadedState | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        (async () => {
            const { data, error } = await api.GET('/api/marketplace-analytics/snapshots', {
                params: { query: { page: 1, perPage: 5 } },
            });
            if (error) {
                setError(JSON.stringify(error));
                return;
            }
            if (data) {
                setState(data);
            }
        })();
    }, []);

    if (error) return <div>Ошибка: {error}</div>;
    if (!state) return <div>Загрузка...</div>;

    return (
        <div>
            <h3>
                Снэпшоты ({state.meta.total} всего, стр. {state.meta.page} из {state.meta.pages})
            </h3>
            <table>
                <thead>
                    <tr>
                        <th>Listing</th>
                        <th>SKU</th>
                        <th>Marketplace</th>
                        <th>Revenue</th>
                        <th>Orders</th>
                    </tr>
                </thead>
                <tbody>
                    {state.data.map((snapshot) => (
                        <tr key={snapshot.id}>
                            <td>{snapshot.listing_name}</td>
                            <td>{snapshot.listing_sku}</td>
                            <td>{snapshot.marketplace}</td>
                            <td>{snapshot.revenue}</td>
                            <td>{snapshot.orders_quantity}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

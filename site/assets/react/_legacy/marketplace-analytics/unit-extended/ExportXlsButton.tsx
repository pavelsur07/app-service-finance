import React, { useState } from 'react';

interface ExportXlsButtonProps {
    marketplace: string;
    periodFrom: string;
    periodTo: string;
    disabled?: boolean;
}

const EXPORT_URL = '/api/marketplace-analytics/unit-extended/export';
const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

function buildExportUrl(marketplace: string, periodFrom: string, periodTo: string): string {
    const params = new URLSearchParams({ periodFrom, periodTo });
    if (marketplace) {
        params.set('marketplace', marketplace);
    }
    return `${EXPORT_URL}?${params.toString()}`;
}

function extractFilename(disposition: string | null, fallback: string): string {
    if (!disposition) return fallback;
    const match = disposition.match(/filename="([^"]+)"/);
    return match?.[1] ?? fallback;
}

function triggerDownload(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

const ExportXlsButton: React.FC<ExportXlsButtonProps> = ({
    marketplace,
    periodFrom,
    periodTo,
    disabled = false,
}) => {
    const [isLoading, setIsLoading] = useState(false);

    const handleClick = async (): Promise<void> => {
        if (isLoading) return;

        setIsLoading(true);
        try {
            const response = await fetch(buildExportUrl(marketplace, periodFrom, periodTo), {
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const contentType = response.headers.get('Content-Type') ?? '';
            if (response.redirected || !contentType.startsWith(XLSX_MIME)) {
                throw new Error('Unexpected response (likely auth redirect)');
            }

            const blob = await response.blob();
            const fallbackName = `unit_extended_${marketplace || 'all'}_${periodFrom}_${periodTo}.xlsx`;
            const filename = extractFilename(response.headers.get('Content-Disposition'), fallbackName);
            triggerDownload(blob, filename);
        } catch (error) {
            console.error('Unit-extended export failed', error);
            window.alert('Не удалось скачать файл');
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <button
            type="button"
            className="btn btn-sm btn-ghost-secondary"
            onClick={handleClick}
            disabled={disabled || isLoading}
        >
            {isLoading ? (
                <>
                    <span className="spinner-border spinner-border-sm me-2" role="status" />
                    Формируем файл…
                </>
            ) : (
                <>
                    <i className="ti ti-download me-1" />
                    Скачать XLS
                </>
            )}
        </button>
    );
};

export default ExportXlsButton;

import React, { useState } from 'react';
import { downloadUnitExtendedExport } from './unitExtended.api';

interface ExportXlsButtonProps {
    marketplace: string;
    periodFrom: string;
    periodTo: string;
    tagIds?: string[];
    tagsMatchAll?: boolean;
    disabled?: boolean;
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
    tagIds = [],
    tagsMatchAll = false,
    disabled = false,
}) => {
    const [isLoading, setIsLoading] = useState(false);

    const handleClick = async (): Promise<void> => {
        if (isLoading) return;

        setIsLoading(true);
        try {
            const { blob, filename } = await downloadUnitExtendedExport({ marketplace, periodFrom, periodTo, tagIds, tagsMatchAll });
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

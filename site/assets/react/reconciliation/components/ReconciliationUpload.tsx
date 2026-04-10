import React, { useCallback, useRef, useState } from "react";

interface ReconciliationUploadProps {
    onUpload: (file: File, periodFrom: string, periodTo: string) => void;
    isLoading: boolean;
    error: string | null;
}

const ReconciliationUpload: React.FC<ReconciliationUploadProps> = ({
    onUpload,
    isLoading,
    error,
}) => {
    const [file, setFile] = useState<File | null>(null);
    const [periodFrom, setPeriodFrom] = useState("");
    const [periodTo, setPeriodTo] = useState("");
    const [dragOver, setDragOver] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    const handleFile = useCallback((f: File | null) => {
        if (!f) return;
        const ext = f.name.split(".").pop()?.toLowerCase();
        if (ext !== "xlsx") return;
        setFile(f);
    }, []);

    const handleDrop = useCallback(
        (e: React.DragEvent) => {
            e.preventDefault();
            setDragOver(false);
            const dropped = e.dataTransfer.files[0];
            if (dropped) handleFile(dropped);
        },
        [handleFile],
    );

    const handleSubmit = useCallback(() => {
        if (!file || !periodFrom || !periodTo) return;
        onUpload(file, periodFrom, periodTo);
    }, [file, periodFrom, periodTo, onUpload]);

    const canSubmit = file !== null && periodFrom !== "" && periodTo !== "" && !isLoading;

    return (
        <div className="card">
            <div className="card-header">
                <h3 className="card-title">Загрузка отчёта</h3>
            </div>
            <div className="card-body">
                <div className="text-muted mb-3">
                    Загрузите файл из ЛК Ozon &rarr; Финансы &rarr; Документы &rarr; Отчёт
                    по начислениям
                </div>

                {/* Drag-and-drop zone */}
                <div
                    className={`border rounded p-4 text-center mb-3 ${
                        dragOver ? "border-primary bg-blue-lt" : "border-secondary"
                    }`}
                    onDragOver={(e) => {
                        e.preventDefault();
                        setDragOver(true);
                    }}
                    onDragLeave={() => setDragOver(false)}
                    onDrop={handleDrop}
                    style={{ cursor: "pointer" }}
                    onClick={() => inputRef.current?.click()}
                >
                    <input
                        ref={inputRef}
                        type="file"
                        accept=".xlsx"
                        className="d-none"
                        onChange={(e) => handleFile(e.target.files?.[0] ?? null)}
                    />
                    {file ? (
                        <div>
                            <i className="ti ti-file-spreadsheet me-2" />
                            <span className="fw-semibold">{file.name}</span>
                            <span className="text-muted ms-2">
                                ({(file.size / 1024 / 1024).toFixed(1)} МБ)
                            </span>
                        </div>
                    ) : (
                        <div className="text-muted">
                            <i className="ti ti-upload me-2" />
                            Перетащите .xlsx файл сюда или нажмите для выбора
                        </div>
                    )}
                </div>

                {/* Date fields */}
                <div className="row mb-3">
                    <div className="col-sm-6">
                        <label className="form-label">Период с</label>
                        <input
                            type="date"
                            className="form-control"
                            value={periodFrom}
                            onChange={(e) => setPeriodFrom(e.target.value)}
                            disabled={isLoading}
                        />
                    </div>
                    <div className="col-sm-6">
                        <label className="form-label">Период по</label>
                        <input
                            type="date"
                            className="form-control"
                            value={periodTo}
                            onChange={(e) => setPeriodTo(e.target.value)}
                            disabled={isLoading}
                        />
                    </div>
                </div>

                {/* Error */}
                {error && (
                    <div className="alert alert-danger" role="alert">
                        {error}
                    </div>
                )}

                {/* Submit */}
                <button
                    className="btn btn-primary"
                    disabled={!canSubmit}
                    onClick={handleSubmit}
                >
                    {isLoading ? (
                        <>
                            <span className="spinner-border spinner-border-sm me-2" />
                            Сверка...
                        </>
                    ) : (
                        <>
                            <i className="ti ti-analyze me-1" />
                            Запустить сверку
                        </>
                    )}
                </button>
            </div>
        </div>
    );
};

export default ReconciliationUpload;

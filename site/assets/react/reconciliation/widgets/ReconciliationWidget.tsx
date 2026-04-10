import React, { useState, useCallback } from "react";
import { useUploadReconciliation } from "../hooks/useUploadReconciliation";
import { useSessionResult } from "../hooks/useSessionResult";
import { useReconciliationHistory } from "../hooks/useReconciliationHistory";
import ReconciliationUpload from "../components/ReconciliationUpload";
import ReconciliationTable from "../components/ReconciliationTable";
import ReconciliationHistory from "../components/ReconciliationHistory";
import type { ReconciliationSession } from "../reconciliation.types";

type View = "upload" | "result" | "history";

const ReconciliationWidget: React.FC = () => {
    const [view, setView] = useState<View>("upload");
    const [activeSessionId, setActiveSessionId] = useState<string | null>(null);
    const [lastUploadResult, setLastUploadResult] = useState<ReconciliationSession | null>(null);
    const [historyPage, setHistoryPage] = useState(1);

    const uploadHook = useUploadReconciliation();
    const sessionQuery = useSessionResult(activeSessionId);
    const historyQuery = useReconciliationHistory(historyPage);

    // Upload callback
    const handleUpload = useCallback(
        (file: File, periodFrom: string, periodTo: string) => {
            uploadHook.upload(file, periodFrom, periodTo);
        },
        [uploadHook],
    );

    // After successful upload — show result
    const prevSession = React.useRef(uploadHook.session);
    React.useEffect(() => {
        if (uploadHook.session && uploadHook.session !== prevSession.current) {
            prevSession.current = uploadHook.session;
            if (uploadHook.session.status === "completed") {
                setLastUploadResult(uploadHook.session);
                setActiveSessionId(null);
                setView("result");
            }
        }
    }, [uploadHook.session]);

    // View session from history
    const handleViewSession = useCallback((sessionId: string) => {
        setLastUploadResult(null);
        setActiveSessionId(sessionId);
        setView("result");
    }, []);

    // Back to upload
    const handleBack = useCallback(() => {
        setActiveSessionId(null);
        setLastUploadResult(null);
        uploadHook.reset();
        setView("upload");
    }, [uploadHook]);

    // Determine which session to show in result view
    const displaySession: ReconciliationSession | null =
        lastUploadResult ?? sessionQuery.session;

    return (
        <div>
            {/* Tabs */}
            <ul className="nav nav-tabs mb-3">
                <li className="nav-item">
                    <button
                        className={`nav-link ${view === "upload" || view === "result" ? "active" : ""}`}
                        onClick={() => {
                            if (view === "result") {
                                handleBack();
                            } else {
                                setView("upload");
                            }
                        }}
                    >
                        <i className="ti ti-upload me-1" />
                        Новая сверка
                    </button>
                </li>
                <li className="nav-item">
                    <button
                        className={`nav-link ${view === "history" ? "active" : ""}`}
                        onClick={() => setView("history")}
                    >
                        <i className="ti ti-history me-1" />
                        История
                    </button>
                </li>
            </ul>

            {/* Upload view */}
            {view === "upload" && (
                <ReconciliationUpload
                    onUpload={handleUpload}
                    isLoading={uploadHook.isLoading}
                    error={uploadHook.error}
                />
            )}

            {/* Result view */}
            {view === "result" && (
                <div>
                    <button className="btn btn-ghost-secondary btn-sm mb-3" onClick={handleBack}>
                        <i className="ti ti-arrow-left me-1" />
                        Назад к сверке
                    </button>

                    {sessionQuery.isLoading && (
                        <div className="card card-body">
                            <div className="d-flex align-items-center gap-2">
                                <div className="spinner-border spinner-border-sm" role="status" />
                                <div className="text-muted">Загрузка результата...</div>
                            </div>
                        </div>
                    )}

                    {sessionQuery.error && !displaySession && (
                        <div className="alert alert-danger">{sessionQuery.error}</div>
                    )}

                    {displaySession?.status === "completed" && (
                        <ReconciliationTable session={displaySession} />
                    )}

                    {displaySession?.status === "failed" && (
                        <div className="alert alert-danger">
                            <div className="fw-semibold">Сверка завершилась с ошибкой</div>
                            <div className="text-muted">
                                {displaySession.errorMessage ?? "Неизвестная ошибка"}
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* History view */}
            {view === "history" && (
                <ReconciliationHistory
                    items={historyQuery.items}
                    total={historyQuery.total}
                    page={historyPage}
                    onPageChange={setHistoryPage}
                    onViewSession={handleViewSession}
                    isLoading={historyQuery.isLoading}
                />
            )}
        </div>
    );
};

export default ReconciliationWidget;

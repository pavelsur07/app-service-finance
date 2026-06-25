import React from 'react';

export function DashboardSkeleton() {
    return (
        <div className="card">
            <div className="card-body">
                <div className="placeholder-glow">
                    <span className="placeholder col-4 mb-2 d-block"></span>
                    <span className="placeholder col-8 mb-2 d-block"></span>
                    <span className="placeholder col-6 d-block"></span>
                </div>
            </div>
        </div>
    );
}

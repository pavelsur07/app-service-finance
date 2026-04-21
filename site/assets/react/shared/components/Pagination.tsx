import React from 'react';

export interface PaginationProps {
    currentPage: number;
    totalPages: number;
    onPageChange: (page: number) => void;
}

type PageItem = number | '…';

function buildPages(currentPage: number, totalPages: number): PageItem[] {
    const delta = 2;

    if (totalPages <= 9) {
        return Array.from({ length: totalPages }, (_, i) => i + 1);
    }

    const pages: PageItem[] = [1];

    let startPage = Math.max(2, currentPage - delta);
    const endPage = Math.min(totalPages - 1, currentPage + delta);

    if (startPage > 2) {
        pages.push('…');
    } else {
        startPage = 2;
    }

    for (let n = startPage; n <= endPage; n++) {
        pages.push(n);
    }

    if (endPage < totalPages - 1) {
        pages.push('…');
    }

    pages.push(totalPages);

    return pages;
}

const Pagination: React.FC<PaginationProps> = ({ currentPage, totalPages, onPageChange }) => {
    if (totalPages <= 1) {
        return null;
    }

    const pages = buildPages(currentPage, totalPages);
    const hasPrev = currentPage > 1;
    const hasNext = currentPage < totalPages;

    const handleClick = (page: number) => (e: React.MouseEvent<HTMLAnchorElement>): void => {
        e.preventDefault();
        if (page !== currentPage && page >= 1 && page <= totalPages) {
            onPageChange(page);
        }
    };

    return (
        <nav aria-label="Пагинация">
            <ul className="pagination justify-content-end mb-0 flex-wrap">
                <li className={`page-item ${hasPrev ? '' : 'disabled'}`}>
                    <a
                        className="page-link"
                        href="#"
                        onClick={handleClick(hasPrev ? currentPage - 1 : currentPage)}
                    >
                        Назад
                    </a>
                </li>

                {pages.map((p, idx) =>
                    p === '…' ? (
                        <li key={`e-${idx}`} className="page-item disabled">
                            <span className="page-link">…</span>
                        </li>
                    ) : (
                        <li
                            key={p}
                            className={`page-item ${p === currentPage ? 'active' : ''}`}
                        >
                            <a
                                className="page-link"
                                href="#"
                                aria-current={p === currentPage ? 'page' : undefined}
                                onClick={handleClick(p)}
                            >
                                {p}
                            </a>
                        </li>
                    )
                )}

                <li className={`page-item ${hasNext ? '' : 'disabled'}`}>
                    <a
                        className="page-link"
                        href="#"
                        onClick={handleClick(hasNext ? currentPage + 1 : currentPage)}
                    >
                        Вперёд
                    </a>
                </li>
            </ul>
        </nav>
    );
};

export default Pagination;

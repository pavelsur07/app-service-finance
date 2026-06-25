import { useCallback, useEffect, useRef, useState } from 'react';
import type { RefCallback, RefObject } from 'react';

interface WrapperRect {
    left: number;
    width: number;
}

interface UseFixedHeaderResult {
    tableWrapperRef: RefCallback<HTMLDivElement>;
    theadRef: RefCallback<HTMLTableSectionElement>;
    showFixed: boolean;
    columnWidths: number[];
    scrollLeft: number;
    wrapperRect: WrapperRect | undefined;
}

export function useFixedHeader(): UseFixedHeaderResult {
    const [theadNode, setTheadNode] = useState<HTMLTableSectionElement | null>(null);
    const [wrapperNode, setWrapperNode] = useState<HTMLDivElement | null>(null);
    // Keep a sync ref for use inside event handlers (getBoundingClientRect, scrollLeft)
    const wrapperNodeRef = useRef<HTMLDivElement | null>(null);

    const [theadAbove, setTheadAbove] = useState(false);
    const [tableInView, setTableInView] = useState(true);
    const [columnWidths, setColumnWidths] = useState<number[]>([]);
    const [scrollLeft, setScrollLeft] = useState(0);
    const [wrapperRect, setWrapperRect] = useState<WrapperRect | undefined>(undefined);

    const theadRef = useCallback((node: HTMLTableSectionElement | null) => {
        setTheadNode(node);
    }, []);

    const tableWrapperRef = useCallback((node: HTMLDivElement | null) => {
        wrapperNodeRef.current = node;
        setWrapperNode(node);
    }, []);

    const captureWidthsAndRect = useCallback((thead: HTMLTableSectionElement) => {
        const ths = thead.querySelectorAll('th');
        setColumnWidths(Array.from(ths).map((th) => th.getBoundingClientRect().width));
        const wrapper = wrapperNodeRef.current;
        if (wrapper) {
            const rect = wrapper.getBoundingClientRect();
            setWrapperRect({ left: rect.left, width: rect.width });
        }
    }, []);

    // Watch thead: detect when it scrolls above the viewport top
    useEffect(() => {
        if (!theadNode) {
            setTheadAbove(false);
            setWrapperRect(undefined);
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                const entry = entries[0];
                if (!entry) return;
                const above = !entry.isIntersecting;
                setTheadAbove(above);
                if (above) {
                    captureWidthsAndRect(theadNode);
                } else {
                    setWrapperRect(undefined);
                }
            },
            { threshold: 0, rootMargin: '0px' }
        );

        observer.observe(theadNode);
        return () => observer.disconnect();
    }, [theadNode, captureWidthsAndRect]);

    // Watch table wrapper: hide fixed header when entire table scrolls past viewport
    useEffect(() => {
        if (!wrapperNode) return;

        const observer = new IntersectionObserver(
            (entries) => {
                const entry = entries[0];
                if (!entry) return;
                setTableInView(entry.isIntersecting);
            },
            { threshold: 0 }
        );

        observer.observe(wrapperNode);
        return () => observer.disconnect();
    }, [wrapperNode]);

    // Horizontal scroll sync
    useEffect(() => {
        if (!wrapperNode) return;

        const handleScroll = () => setScrollLeft(wrapperNode.scrollLeft);
        wrapperNode.addEventListener('scroll', handleScroll, { passive: true });
        return () => wrapperNode.removeEventListener('scroll', handleScroll);
    }, [wrapperNode]);

    // Resize: recalculate column widths and wrapper rect
    useEffect(() => {
        if (!theadNode || !theadAbove) return;

        const updateOnResize = () => captureWidthsAndRect(theadNode);
        window.addEventListener('resize', updateOnResize);
        return () => window.removeEventListener('resize', updateOnResize);
    }, [theadNode, theadAbove, captureWidthsAndRect]);

    return {
        tableWrapperRef,
        theadRef,
        showFixed: theadAbove && tableInView,
        columnWidths,
        scrollLeft,
        wrapperRect,
    };
}

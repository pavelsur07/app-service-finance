import { useEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';

interface UseFixedHeaderResult {
    tableWrapperRef: RefObject<HTMLDivElement>;
    theadRef: RefObject<HTMLTableSectionElement>;
    showFixed: boolean;
    columnWidths: number[];
    scrollLeft: number;
}

export function useFixedHeader(): UseFixedHeaderResult {
    const tableWrapperRef = useRef<HTMLDivElement>(null);
    const theadRef = useRef<HTMLTableSectionElement>(null);
    const [showFixed, setShowFixed] = useState(false);
    const [columnWidths, setColumnWidths] = useState<number[]>([]);
    const [scrollLeft, setScrollLeft] = useState(0);

    useEffect(() => {
        const thead = theadRef.current;
        if (!thead) return;

        const observer = new IntersectionObserver(
            (entries) => {
                const entry = entries[0];
                if (!entry) return;
                setShowFixed(!entry.isIntersecting);
                if (!entry.isIntersecting) {
                    const ths = thead.querySelectorAll('th');
                    setColumnWidths(Array.from(ths).map((th) => th.getBoundingClientRect().width));
                }
            },
            { threshold: 0, rootMargin: '0px' }
        );

        observer.observe(thead);
        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        const wrapper = tableWrapperRef.current;
        if (!wrapper) return;

        const handleScroll = () => setScrollLeft(wrapper.scrollLeft);
        wrapper.addEventListener('scroll', handleScroll, { passive: true });
        return () => wrapper.removeEventListener('scroll', handleScroll);
    }, []);

    useEffect(() => {
        const updateWidths = () => {
            const thead = theadRef.current;
            if (thead && showFixed) {
                const ths = thead.querySelectorAll('th');
                setColumnWidths(Array.from(ths).map((th) => th.getBoundingClientRect().width));
            }
        };
        window.addEventListener('resize', updateWidths);
        return () => window.removeEventListener('resize', updateWidths);
    }, [showFixed]);

    return { tableWrapperRef, theadRef, showFixed, columnWidths, scrollLeft };
}

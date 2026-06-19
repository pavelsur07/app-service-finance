import React, { useEffect, useMemo, useRef } from 'react';
import type { ShopOption } from '../types';

export const SELECTED_SHOP_STORAGE_KEY = 'ingestion.selected_shop';

interface ShopSelectorProps {
    shops: ShopOption[];
    value: string | null;
    onChange: (shopRef: string | null) => void;
    includeAll?: boolean;
    disabled?: boolean;
    label?: string;
}

function shopRef(option: ShopOption): string | null {
    return typeof option.shop_ref === 'string' && option.shop_ref.trim() !== ''
        ? option.shop_ref.trim()
        : null;
}

function shopLabel(option: ShopOption): string {
    const ref = shopRef(option);
    const label = typeof option.label === 'string' && option.label.trim() !== ''
        ? option.label.trim()
        : ref;

    return label ?? 'Без названия';
}

function readStoredShop(): string | null {
    try {
        const stored = window.localStorage.getItem(SELECTED_SHOP_STORAGE_KEY);

        return stored !== null && stored.trim() !== '' ? stored : null;
    } catch {
        return null;
    }
}

function writeStoredShop(shopRefValue: string | null): void {
    try {
        if (shopRefValue === null || shopRefValue.trim() === '') {
            window.localStorage.removeItem(SELECTED_SHOP_STORAGE_KEY);
            return;
        }

        window.localStorage.setItem(SELECTED_SHOP_STORAGE_KEY, shopRefValue);
    } catch {
        // localStorage can be unavailable in private modes; the selector still works.
    }
}

export function initialStoredShop(): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    return readStoredShop();
}

const ShopSelector: React.FC<ShopSelectorProps> = ({
    shops,
    value,
    onChange,
    includeAll = true,
    disabled = false,
    label = 'Магазин',
}) => {
    const appliedStoredValueRef = useRef(false);
    const options = useMemo(() => {
        const seen = new Set<string>();

        return shops
            .map((shop) => {
                const ref = shopRef(shop);

                return ref === null ? null : { ref, label: shopLabel(shop) };
            })
            .filter((shop): shop is { ref: string; label: string } => {
                if (shop === null || seen.has(shop.ref)) {
                    return false;
                }

                seen.add(shop.ref);

                return true;
            });
    }, [shops]);

    const hasSelectedOption = value !== null && options.some((option) => option.ref === value);

    useEffect(() => {
        if (appliedStoredValueRef.current || options.length === 0) {
            return;
        }

        appliedStoredValueRef.current = true;

        const stored = readStoredShop();
        if (stored !== null && options.some((option) => option.ref === stored) && stored !== value) {
            onChange(stored);
        }
    }, [onChange, options, value]);

    const handleChange = (event: React.ChangeEvent<HTMLSelectElement>): void => {
        const nextValue = event.target.value === '' ? null : event.target.value;

        writeStoredShop(nextValue);
        onChange(nextValue);
    };

    return (
        <div>
            <label className="form-label">{label}</label>
            <select
                className="form-select"
                value={value ?? ''}
                onChange={handleChange}
                disabled={disabled}
            >
                {includeAll ? (
                    <option value="">Все магазины</option>
                ) : (
                    <option value="">Выберите магазин</option>
                )}
                {!hasSelectedOption && value !== null && value.trim() !== '' && (
                    <option value={value}>{value}</option>
                )}
                {options.map((option) => (
                    <option key={option.ref} value={option.ref}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );
};

export default ShopSelector;

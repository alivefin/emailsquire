import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useIsPhone } from './useIsPhone';

afterEach(() => vi.unstubAllGlobals());

describe('useIsPhone', () => {
    it('follows the media query and updates on change', () => {
        let matches = true;
        const listeners = new Set<() => void>();
        const query = vi.fn((q: string) => ({
            get matches() {
                return matches;
            },
            media: q,
            addEventListener: (_: string, l: () => void) => listeners.add(l),
            removeEventListener: (_: string, l: () => void) => listeners.delete(l),
        }));
        vi.stubGlobal('matchMedia', query);
        const { result } = renderHook(() => useIsPhone());
        expect(query).toHaveBeenCalledWith('(max-width: 767.98px)');
        expect(result.current).toBe(true);
        act(() => {
            matches = false;
            listeners.forEach((l) => l());
        });
        expect(result.current).toBe(false);
    });
});

import { createContext, useContext, useEffect, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

interface SlotValue {
    /** The element in the mounted bar (desktop header or phone bar) that domain pages fill. */
    target: HTMLElement | null;
    setTarget: (el: HTMLElement | null) => void;
    /** Number of mounted `HeaderSlot`s; the bars show their fallback title only while it is 0. */
    filled: number;
    register: () => () => void;
}

export const HeaderSlotContext = createContext<SlotValue>({
    target: null,
    setTarget: () => {},
    filled: 0,
    register: () => () => {},
});

/** Renders its children into the title area of whichever bar is mounted, replacing the fallback title. */
export function HeaderSlot({ children }: { children: ReactNode }) {
    const { target, register } = useContext(HeaderSlotContext);
    useEffect(() => register(), [register]);
    return target ? createPortal(children, target) : null;
}

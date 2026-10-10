import { createContext, useContext, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

/** The element in the desktop header that domain pages fill. Set by `Header`, read by `HeaderSlot`. */
export const HeaderSlotContext = createContext<{ target: HTMLElement | null; setTarget: (el: HTMLElement | null) => void }>({
    target: null,
    setTarget: () => {},
});

/** Renders its children into the header's title area. Used by domain pages in place of the plain title. */
export function HeaderSlot({ children }: { children: ReactNode }) {
    const { target } = useContext(HeaderSlotContext);
    return target ? createPortal(children, target) : null;
}

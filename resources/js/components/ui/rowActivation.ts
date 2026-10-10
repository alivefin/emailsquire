import type { KeyboardEvent, MouseEvent } from 'react';

const INTERACTIVE = 'button, a, input, select, textarea, label, [role="menu"], [role="menuitem"], [role="button"]';

function fromInner(e: { target: EventTarget; currentTarget: Element }): boolean {
    const hit = (e.target as Element).closest(INTERACTIVE);
    return !!hit && e.currentTarget.contains(hit);
}

/** Props that make a row or card activatable by click, Enter and Space, ignoring events from inner controls. */
export function rowActivation(activate: () => void) {
    return {
        tabIndex: 0,
        onClick: (e: MouseEvent<HTMLElement>) => {
            if (!fromInner(e)) activate();
        },
        onKeyDown: (e: KeyboardEvent<HTMLElement>) => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            if (fromInner(e)) return;
            e.preventDefault();
            activate();
        },
    };
}

export const labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink';

export const controlClass =
    'h-11 w-full min-w-0 rounded-md border bg-white px-3 text-sm text-ink outline-none placeholder:text-faint ' +
    'focus:border-teal focus:ring-2 focus:ring-teal/20 disabled:bg-subtle disabled:text-muted max-md:h-12 max-md:text-base';

export const borderFor = (error?: string) => (error ? 'border-danger' : 'border-line-strong');

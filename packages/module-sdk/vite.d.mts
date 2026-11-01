import type { Plugin } from 'vite';

/** The packages a module bundle reads from the core's host object instead of bundling. */
export const SHARED: readonly string[];

export function emailsquireModule(options: { name: string; entry: string }): Plugin[];

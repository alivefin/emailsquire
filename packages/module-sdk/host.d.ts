import type { Host } from '../../resources/js/modules/host';

export type { Host, ModuleDefinition } from '../../resources/js/modules/host';

declare global {
    // Set by the console before any module bundle runs.
    var __emailsquire: Host;
}

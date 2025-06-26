import { ExportState, SelectorArgs, ExportArgs } from './types';
export declare const isExportRequesting: (state: ExportState, selector: string, selectorArgs: SelectorArgs) => boolean;
export declare const getExportId: (state: ExportState, exportType: string, exportArgs: ExportArgs) => string;
export declare const getError: (state: ExportState, selector: string, selectorArgs: SelectorArgs) => unknown;
//# sourceMappingURL=selectors.d.ts.map
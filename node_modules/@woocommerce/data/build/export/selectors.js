"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.getError = exports.getExportId = exports.isExportRequesting = void 0;
/**
 * Internal dependencies
 */
const utils_1 = require("./utils");
const isExportRequesting = (state, selector, selectorArgs) => {
    return Boolean(state.requesting[selector] &&
        state.requesting[selector][(0, utils_1.hashExportArgs)(selectorArgs)]);
};
exports.isExportRequesting = isExportRequesting;
const getExportId = (state, exportType, exportArgs) => {
    return (state.exportIds[exportType] &&
        state.exportIds[exportType][(0, utils_1.hashExportArgs)(exportArgs)]);
};
exports.getExportId = getExportId;
const getError = (state, selector, selectorArgs) => {
    return (state.errors[selector] &&
        state.errors[selector][(0, utils_1.hashExportArgs)(selectorArgs)]);
};
exports.getError = getError;

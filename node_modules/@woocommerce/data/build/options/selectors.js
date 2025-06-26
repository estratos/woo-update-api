"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.getOptionsUpdatingError = exports.isOptionsUpdating = exports.getOptionsRequestingError = exports.getOption = void 0;
/**
 * Get option from state tree.
 *
 * @param {Object} state - Reducer state
 * @param {Array}  name  - Option name
 */
const getOption = (state, name) => {
    return state[name];
};
exports.getOption = getOption;
/**
 * Determine if an options request resulted in an error.
 *
 * @param {Object} state - Reducer state
 * @param {string} name  - Option name
 */
const getOptionsRequestingError = (state, name) => {
    return state.requestingErrors[name] || false;
};
exports.getOptionsRequestingError = getOptionsRequestingError;
/**
 * Determine if options are being updated.
 *
 * @param {Object} state - Reducer state
 */
const isOptionsUpdating = (state) => {
    return state.isUpdating || false;
};
exports.isOptionsUpdating = isOptionsUpdating;
/**
 * Determine if an options update resulted in an error.
 *
 * @param {Object} state - Reducer state
 */
const getOptionsUpdatingError = (state) => {
    return state.updatingError || false;
};
exports.getOptionsUpdatingError = getOptionsUpdatingError;

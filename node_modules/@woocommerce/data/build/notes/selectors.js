"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.isNotesRequesting = exports.getNotesError = exports.getNotes = void 0;
/**
 * External dependencies
 */
const rememo_1 = __importDefault(require("rememo"));
exports.getNotes = (0, rememo_1.default)((state, query) => {
    const noteIds = state.noteQueries[JSON.stringify(query)] || [];
    return noteIds.map((id) => state.notes[id]);
}, (state, query) => [
    state.noteQueries[JSON.stringify(query)],
    state.notes,
]);
const getNotesError = (state, selector) => {
    return state.errors[selector] || false;
};
exports.getNotesError = getNotesError;
const isNotesRequesting = (state, selector) => {
    return state.requesting[selector] || false;
};
exports.isNotesRequesting = isNotesRequesting;

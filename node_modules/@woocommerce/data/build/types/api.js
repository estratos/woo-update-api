"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.isRestApiError = void 0;
const isRestApiError = (error) => error.code !== undefined &&
    error.message !== undefined;
exports.isRestApiError = isRestApiError;

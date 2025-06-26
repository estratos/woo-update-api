"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.hashExportArgs = void 0;
/**
 * External dependencies
 */
const md5_1 = __importDefault(require("md5"));
/**
 * Internal dependencies
 */
const utils_1 = require("../utils");
const hashExportArgs = (args) => {
    return (0, md5_1.default)((0, utils_1.getResourceName)('export', args));
};
exports.hashExportArgs = hashExportArgs;

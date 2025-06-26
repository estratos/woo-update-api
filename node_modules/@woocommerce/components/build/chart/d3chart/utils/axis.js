"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.drawAxis = void 0;
/**
 * Internal dependencies
 */
const axis_x_1 = require("./axis-x");
const axis_y_1 = require("./axis-y");
const drawAxis = (node, params, scales, formats, margin, isRTL) => {
    (0, axis_x_1.drawXAxis)(node, params, scales, formats);
    (0, axis_y_1.drawYAxis)(node, scales, formats, margin, isRTL);
    node.selectAll('.domain').remove();
    node.selectAll('.axis .tick line').remove();
};
exports.drawAxis = drawAxis;

"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.drawBars = void 0;
/**
 * External dependencies
 */
const lodash_1 = require("lodash");
const d3_selection_1 = require("d3-selection");
const moment_1 = __importDefault(require("moment"));
const drawBars = (node, data, params, scales, formats, tooltip) => {
    const height = scales.yScale.range()[0];
    const barGroup = node
        .append('g')
        .attr('class', 'bars')
        .selectAll('g')
        .data(data)
        .enter()
        .append('g')
        .attr('transform', (d) => `translate(${scales.xScale(d.date)}, 0)`)
        .attr('class', 'bargroup')
        .attr('role', 'region')
        .attr('aria-label', (d) => params.mode === 'item-comparison'
        ? formats.screenReaderFormat(d.date instanceof Date
            ? d.date
            : (0, moment_1.default)(d.date).toDate())
        : null);
    barGroup
        .append('rect')
        .attr('class', 'barfocus')
        .attr('x', 0)
        .attr('y', 0)
        .attr('width', scales.xGroupScale.range()[1])
        .attr('height', height)
        .attr('opacity', '0')
        .on('mouseover', (d, i, nodes) => {
        tooltip.show(data.find((e) => e.date === d.date), d3_selection_1.event.target, nodes[i].parentNode);
    })
        .on('mouseout', () => tooltip.hide());
    const basePosition = scales.yScale(0);
    barGroup
        .selectAll('.bar')
        .data((d) => params.visibleKeys.map((row) => ({
        key: row.key,
        focus: row.focus,
        value: (0, lodash_1.get)(d, [row.key, 'value'], 0),
        label: row.label,
        visible: row.visible,
        date: d.date,
    })))
        .enter()
        .append('rect')
        .attr('class', 'bar')
        .attr('x', (d) => scales.xGroupScale(d.key))
        .attr('y', (d) => Math.min(basePosition, scales.yScale(d.value)))
        .attr('width', scales.xGroupScale.bandwidth())
        .attr('height', (d) => Math.abs(basePosition - scales.yScale(d.value)))
        .attr('fill', (d) => params.getColor(d.key))
        .attr('pointer-events', 'none')
        .attr('tabindex', '0')
        .attr('aria-label', (d) => {
        let label = d.label || d.key;
        if (params.mode === 'time-comparison') {
            const dayData = data.find((e) => e.date === d.date);
            label = formats.screenReaderFormat((0, moment_1.default)(dayData[d.key].labelDate).toDate());
        }
        return `${label} ${tooltip.valueFormat(d.value)}`;
    })
        .style('opacity', (d) => {
        const opacity = d.focus ? 1 : 0.1;
        return d.visible ? opacity : 0;
    })
        .on('focus', (d, i, nodes) => {
        const targetNode = d.value > 0 ? d3_selection_1.event.target : d3_selection_1.event.target.parentNode;
        tooltip.show(data.find((e) => e.date === d.date), targetNode, nodes[i].parentNode);
    })
        .on('blur', () => tooltip.hide());
};
exports.drawBars = drawBars;

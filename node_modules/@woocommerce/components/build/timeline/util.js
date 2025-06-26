"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.sortByDateUsing = exports.orderByOptions = exports.groupItemsUsing = exports.groupByOptions = void 0;
/**
 * External dependencies
 */
const moment_1 = __importDefault(require("moment"));
const orderByOptions = {
    ASC: 'asc',
    DESC: 'desc',
};
exports.orderByOptions = orderByOptions;
const groupByOptions = {
    DAY: 'day',
    WEEK: 'week',
    MONTH: 'month',
};
exports.groupByOptions = groupByOptions;
const sortAscending = (groupA, groupB) => groupA.date.getTime() - groupB.date.getTime();
const sortDescending = (groupA, groupB) => groupB.date.getTime() - groupA.date.getTime();
const sortByDateUsing = (orderBy) => {
    switch (orderBy) {
        case orderByOptions.ASC:
            return sortAscending;
        case orderByOptions.DESC:
        default:
            return sortDescending;
    }
};
exports.sortByDateUsing = sortByDateUsing;
const groupItemsUsing = (groupBy) => (groups, newItem) => {
    // Helper functions defined to make the logic a bit more readable.
    const hasSameMoment = (group, item) => {
        return (0, moment_1.default)(group.date).isSame((0, moment_1.default)(item.date), groupBy);
    };
    const groupIndexExists = (index) => index >= 0;
    const groupForItem = groups.findIndex((group) => hasSameMoment(group, newItem));
    if (!groupIndexExists(groupForItem)) {
        // Create new group for newItem.
        return [
            ...groups,
            {
                date: newItem.date,
                items: [newItem],
            },
        ];
    }
    groups[groupForItem].items.push(newItem);
    return groups;
};
exports.groupItemsUsing = groupItemsUsing;

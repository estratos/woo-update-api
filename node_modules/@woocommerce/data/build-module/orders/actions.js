/**
 * Internal dependencies
 */
import TYPES from './action-types';
export function getOrderSuccess(id, order) {
    return {
        type: TYPES.GET_ORDER_SUCCESS,
        id,
        order,
    };
}
export function getOrderError(query, error) {
    return {
        type: TYPES.GET_ORDER_ERROR,
        query,
        error,
    };
}
export function getOrdersSuccess(query, orders, totalCount) {
    return {
        type: TYPES.GET_ORDERS_SUCCESS,
        orders,
        query,
        totalCount,
    };
}
export function getOrdersError(query, error) {
    return {
        type: TYPES.GET_ORDERS_ERROR,
        query,
        error,
    };
}
export function getOrdersTotalCountSuccess(query, totalCount) {
    return {
        type: TYPES.GET_ORDERS_TOTAL_COUNT_SUCCESS,
        query,
        totalCount,
    };
}
export function getOrdersTotalCountError(query, error) {
    return {
        type: TYPES.GET_ORDERS_TOTAL_COUNT_ERROR,
        query,
        error,
    };
}

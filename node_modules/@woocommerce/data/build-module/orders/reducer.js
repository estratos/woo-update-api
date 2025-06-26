/**
 * Internal dependencies
 */
import TYPES from './action-types';
import { getOrderResourceName, getTotalOrderCountResourceName } from './utils';
const reducer = (state = {
    orders: {},
    ordersCount: {},
    errors: {},
    data: {},
}, payload) => {
    if (payload && 'type' in payload) {
        switch (payload.type) {
            case TYPES.GET_ORDER_SUCCESS:
                const orderData = state.data || {};
                return {
                    ...state,
                    data: {
                        ...orderData,
                        [payload.id]: {
                            ...(orderData[payload.id] || {}),
                            ...payload.order,
                        },
                    },
                };
            case TYPES.GET_ORDERS_SUCCESS:
                const ids = [];
                const nextOrders = payload.orders.reduce((result, order) => {
                    ids.push(order.id);
                    result[order.id] = {
                        ...(state.data[order.id] || {}),
                        ...order,
                    };
                    return result;
                }, {});
                const resourceName = getOrderResourceName(payload.query);
                return {
                    ...state,
                    orders: {
                        ...state.orders,
                        [resourceName]: { data: ids },
                    },
                    data: {
                        ...state.data,
                        ...nextOrders,
                    },
                };
            case TYPES.GET_ORDERS_TOTAL_COUNT_SUCCESS:
                const totalResourceName = getTotalOrderCountResourceName(payload.query);
                return {
                    ...state,
                    ordersCount: {
                        ...state.ordersCount,
                        [totalResourceName]: payload.totalCount,
                    },
                };
            case TYPES.GET_ORDER_ERROR:
            case TYPES.GET_ORDERS_ERROR:
            case TYPES.GET_ORDERS_TOTAL_COUNT_ERROR:
                return {
                    ...state,
                    errors: {
                        ...state.errors,
                        [getOrderResourceName(payload.query)]: payload.error,
                    },
                };
            default:
                return state;
        }
    }
    return state;
};
export default reducer;

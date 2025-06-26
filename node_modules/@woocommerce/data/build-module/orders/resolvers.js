/**
 * Internal dependencies
 */
import { WC_ORDERS_NAMESPACE } from './constants';
import { getOrdersError, getOrdersSuccess, getOrdersTotalCountError, getOrdersTotalCountSuccess, } from './actions';
import { request } from '../utils';
export function* getOrders(query) {
    // id is always required.
    const ordersQuery = {
        ...query,
    };
    if (ordersQuery &&
        ordersQuery._fields &&
        !ordersQuery._fields.includes('id')) {
        ordersQuery._fields = ['id', ...ordersQuery._fields];
    }
    try {
        const { items, totalCount } = yield request(WC_ORDERS_NAMESPACE, ordersQuery);
        yield getOrdersTotalCountSuccess(query, totalCount);
        yield getOrdersSuccess(query, items, totalCount);
        return items;
    }
    catch (error) {
        yield getOrdersError(query, error);
        return error;
    }
}
export function* getOrdersTotalCount(query) {
    try {
        const totalsQuery = {
            ...query,
            page: 1,
            per_page: 1,
        };
        const { totalCount } = yield request(WC_ORDERS_NAMESPACE, totalsQuery);
        yield getOrdersTotalCountSuccess(query, totalCount);
        return totalCount;
    }
    catch (error) {
        yield getOrdersTotalCountError(query, error);
        return error;
    }
}

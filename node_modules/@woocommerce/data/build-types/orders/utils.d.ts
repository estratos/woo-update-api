import { OrdersQuery } from './types';
/**
 * Generate a resource name for orders.
 *
 * @param {Object} query Query for orders.
 * @return {string} Resource name for orders.
 */
export declare function getOrderResourceName(query: Partial<OrdersQuery>): string;
/**
 * Generate a resource name for order totals count.
 *
 * It omits query parameters from the identifier that don't affect
 * totals values like pagination and response field filtering.
 *
 * @param {Object} query Query for order totals count.
 * @return {string} Resource name for order totals.
 */
export declare function getTotalOrderCountResourceName(query: Partial<OrdersQuery>): string;
//# sourceMappingURL=utils.d.ts.map
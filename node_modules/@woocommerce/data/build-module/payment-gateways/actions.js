/**
 * External dependencies
 */
import { apiFetch } from '@wordpress/data-controls';
/**
 * Internal dependencies
 */
import { ACTION_TYPES } from './action-types';
import { API_NAMESPACE } from './constants';
export function getPaymentGatewaysRequest() {
    return {
        type: ACTION_TYPES.GET_PAYMENT_GATEWAYS_REQUEST,
    };
}
export function getPaymentGatewaysSuccess(paymentGateways) {
    return {
        type: ACTION_TYPES.GET_PAYMENT_GATEWAYS_SUCCESS,
        paymentGateways,
    };
}
export function getPaymentGatewaysError(error) {
    return {
        type: ACTION_TYPES.GET_PAYMENT_GATEWAYS_ERROR,
        error,
    };
}
export function getPaymentGatewayRequest() {
    return {
        type: ACTION_TYPES.GET_PAYMENT_GATEWAY_REQUEST,
    };
}
export function getPaymentGatewayError(error) {
    return {
        type: ACTION_TYPES.GET_PAYMENT_GATEWAY_ERROR,
        error,
    };
}
export function getPaymentGatewaySuccess(paymentGateway) {
    return {
        type: ACTION_TYPES.GET_PAYMENT_GATEWAY_SUCCESS,
        paymentGateway,
    };
}
export function updatePaymentGatewaySuccess(paymentGateway) {
    return {
        type: ACTION_TYPES.UPDATE_PAYMENT_GATEWAY_SUCCESS,
        paymentGateway,
    };
}
export function updatePaymentGatewayRequest() {
    return {
        type: ACTION_TYPES.UPDATE_PAYMENT_GATEWAY_REQUEST,
    };
}
export function updatePaymentGatewayError(error) {
    return {
        type: ACTION_TYPES.UPDATE_PAYMENT_GATEWAY_ERROR,
        error,
    };
}
export function* updatePaymentGateway(id, data) {
    try {
        yield updatePaymentGatewayRequest();
        const response = yield apiFetch({
            method: 'PUT',
            path: API_NAMESPACE + '/payment_gateways/' + id,
            body: JSON.stringify(data),
        });
        if (response && response.id === id) {
            // Update the already loaded payment gateway list with the new data
            yield updatePaymentGatewaySuccess(response);
            return response;
        }
    }
    catch (e) {
        yield updatePaymentGatewayError(e);
        throw e;
    }
}

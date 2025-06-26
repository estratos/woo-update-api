export function getPaymentGateway(state, id) {
    return state.paymentGateways.find((paymentGateway) => paymentGateway.id === id);
}
export function getPaymentGateways(state) {
    return state.paymentGateways;
}
export function getPaymentGatewayError(state, selector) {
    return state.errors[selector] || null;
}
export function isPaymentGatewayUpdating(state) {
    return state.isUpdating || false;
}

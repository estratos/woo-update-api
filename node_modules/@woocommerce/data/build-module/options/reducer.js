/**
 * External dependencies
 */
/**
 * Internal dependencies
 */
import TYPES from './action-types';
const optionsReducer = (state = { isUpdating: false, requestingErrors: {} }, action) => {
    switch (action.type) {
        case TYPES.RECEIVE_OPTIONS:
            state = {
                ...state,
                ...action.options,
            };
            break;
        case TYPES.SET_IS_UPDATING:
            state = {
                ...state,
                isUpdating: action.isUpdating,
            };
            break;
        case TYPES.SET_REQUESTING_ERROR:
            state = {
                ...state,
                requestingErrors: {
                    [action.name]: action.error,
                },
            };
            break;
        case TYPES.SET_UPDATING_ERROR:
            state = {
                ...state,
                error: action.error,
                updatingError: action.error,
                isUpdating: false,
            };
            break;
    }
    return state;
};
export default optionsReducer;

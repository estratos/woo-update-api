/**
 * External dependencies
 */
/**
 * Internal dependencies
 */
import TYPES from './action-types';
import { getResourceName } from '../utils';
import { getTotalCountResourceName } from './utils';
const initialState = {
    items: {},
    errors: {},
    data: {},
};
const reducer = (state = initialState, action) => {
    switch (action.type) {
        case TYPES.SET_ITEM:
            const itemData = state.data[action.itemType] || {};
            return {
                ...state,
                data: {
                    ...state.data,
                    [action.itemType]: {
                        ...itemData,
                        [action.id]: {
                            ...(itemData[action.id] || {}),
                            ...action.item,
                        },
                    },
                },
            };
        case TYPES.SET_ITEMS:
            const ids = [];
            const nextItems = action.items.reduce((result, theItem) => {
                ids.push(theItem.id);
                result[theItem.id] = theItem;
                return result;
            }, {});
            const resourceName = getResourceName(action.itemType, action.query);
            return {
                ...state,
                items: {
                    ...state.items,
                    [resourceName]: { data: ids },
                },
                data: {
                    ...state.data,
                    [action.itemType]: {
                        ...state.data[action.itemType],
                        ...nextItems,
                    },
                },
            };
        case TYPES.SET_ITEMS_TOTAL_COUNT:
            const totalResourceName = getTotalCountResourceName(action.itemType, action.query);
            return {
                ...state,
                items: {
                    ...state.items,
                    [totalResourceName]: action.totalCount,
                },
            };
        case TYPES.SET_ERROR:
            return {
                ...state,
                errors: {
                    ...state.errors,
                    [getResourceName(action.itemType, action.query)]: action.error,
                },
            };
        default:
            return state;
    }
};
export default reducer;

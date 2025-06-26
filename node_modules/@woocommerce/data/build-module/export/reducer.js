/**
 * External dependencies
 */
/**
 * Internal dependencies
 */
import TYPES from './action-types';
import { hashExportArgs } from './utils';
const reducer = (state = {
    errors: {},
    requesting: {},
    exportMeta: {},
    exportIds: {},
}, action) => {
    switch (action.type) {
        case TYPES.SET_IS_REQUESTING:
            return {
                ...state,
                requesting: {
                    ...state.requesting,
                    [action.selector]: {
                        ...state.requesting[action.selector],
                        [hashExportArgs(action.selectorArgs)]: action.isRequesting,
                    },
                },
            };
        case TYPES.SET_EXPORT_ID:
            const { exportType, exportArgs, exportId } = action;
            return {
                ...state,
                exportMeta: {
                    ...state.exportMeta,
                    [exportId]: {
                        exportType,
                        exportArgs,
                    },
                },
                exportIds: {
                    ...state.exportIds,
                    [exportType]: {
                        ...state.exportIds[exportType],
                        [hashExportArgs({
                            type: exportType,
                            args: exportArgs,
                        })]: exportId,
                    },
                },
            };
        case TYPES.SET_ERROR:
            return {
                ...state,
                errors: {
                    ...state.errors,
                    [action.selector]: {
                        ...state.errors[action.selector],
                        [hashExportArgs(action.selectorArgs)]: action.error,
                    },
                },
            };
        default:
            return state;
    }
};
export default reducer;

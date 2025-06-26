"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.useSelectWithRefresh = void 0;
/**
 * External dependencies
 */
const element_1 = require("@wordpress/element");
const data_1 = require("@wordpress/data");
const useInterval = (callback, interval) => {
    const savedCallback = (0, element_1.useRef)();
    (0, element_1.useEffect)(() => {
        savedCallback.current = callback;
    }, [callback]);
    (0, element_1.useEffect)(() => {
        const handler = (...args) => {
            if (savedCallback.current) {
                savedCallback.current(...args);
            }
        };
        if (interval !== null) {
            const id = setInterval(handler, interval);
            return () => clearInterval(id);
        }
    }, [interval]);
};
const useSelectWithRefresh = (mapSelectToProps, invalidationCallback, interval, dependencies) => {
    const result = (0, data_1.useSelect)(mapSelectToProps, dependencies);
    useInterval(invalidationCallback, interval);
    return result;
};
exports.useSelectWithRefresh = useSelectWithRefresh;

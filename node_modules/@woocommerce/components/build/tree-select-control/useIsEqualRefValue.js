"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
/**
 * External dependencies
 */
const lodash_1 = require("lodash");
const element_1 = require("@wordpress/element");
/**
 * Stores value in a ref. In subsequent render, value will be compared with ref.current using `isEqual` comparison.
 * If it is equal, returns ref.current; else, set ref.current to be value.
 *
 * This is useful for objects used in hook dependencies.
 *
 * @param {*} value Value to be stored in ref.
 * @return {*} Value stored in ref.
 */
const useIsEqualRefValue = (value) => {
    const optionsRef = (0, element_1.useRef)(value);
    if (!(0, lodash_1.isEqual)(optionsRef.current, value)) {
        optionsRef.current = value;
    }
    return optionsRef.current;
};
exports.default = useIsEqualRefValue;

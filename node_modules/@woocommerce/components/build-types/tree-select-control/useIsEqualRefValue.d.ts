export default useIsEqualRefValue;
/**
 * Stores value in a ref. In subsequent render, value will be compared with ref.current using `isEqual` comparison.
 * If it is equal, returns ref.current; else, set ref.current to be value.
 *
 * This is useful for objects used in hook dependencies.
 *
 * @param {*} value Value to be stored in ref.
 * @return {*} Value stored in ref.
 */
declare function useIsEqualRefValue(value: any): any;
//# sourceMappingURL=useIsEqualRefValue.d.ts.map
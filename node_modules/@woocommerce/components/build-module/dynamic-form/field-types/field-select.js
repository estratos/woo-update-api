/**
 * External dependencies
 */
import { createElement, useMemo } from '@wordpress/element';
/**
 * Internal dependencies
 */
import { SelectControl } from '../../index';
const transformOptions = (options) => Object.entries(options).map(([key, value]) => ({
    key,
    label: value,
    value: { id: key },
}));
export const SelectField = ({ field, ...props }) => {
    const { description, label, options = {} } = field;
    const transformedOptions = useMemo(() => transformOptions(options), [options]);
    return (createElement(SelectControl, { title: description, label: label, options: transformedOptions, ...props }));
};

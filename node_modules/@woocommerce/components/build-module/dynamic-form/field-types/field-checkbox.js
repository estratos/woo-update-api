/**
 * External dependencies
 */
import { CheckboxControl } from '@wordpress/components';
import { createElement } from '@wordpress/element';
export const CheckboxField = ({ field, onChange, ...props }) => {
    const { label, description } = field;
    return (createElement(CheckboxControl, { onChange: (val) => onChange(val), title: description, label: label, ...props }));
};

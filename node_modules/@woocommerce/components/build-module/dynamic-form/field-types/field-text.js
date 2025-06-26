/**
 * External dependencies
 */
import { createElement } from '@wordpress/element';
/**
 * Internal dependencies
 */
import { TextControl } from '../../index';
export const TextField = ({ field, type = 'text', ...props }) => {
    const { label, description } = field;
    return (createElement(TextControl, { type: type, title: description, label: label, ...props }));
};

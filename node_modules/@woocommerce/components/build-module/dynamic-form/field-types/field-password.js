/**
 * External dependencies
 */
import { createElement } from '@wordpress/element';
/**
 * Internal dependencies
 */
import { TextField } from './field-text';
export const PasswordField = (props) => {
    return createElement(TextField, { ...props, type: "password" });
};

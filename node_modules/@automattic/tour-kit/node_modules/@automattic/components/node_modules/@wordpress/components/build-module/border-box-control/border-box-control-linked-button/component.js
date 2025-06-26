/**
 * WordPress dependencies
 */
import { link, linkOff } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Button from '../../button';
import Tooltip from '../../tooltip';
import { View } from '../../view';
import { contextConnect } from '../../context';
import { useBorderBoxControlLinkedButton } from './hook';
import { jsx as _jsx } from "react/jsx-runtime";
const BorderBoxControlLinkedButton = (props, forwardedRef) => {
  const {
    className,
    isLinked,
    ...buttonProps
  } = useBorderBoxControlLinkedButton(props);
  const label = isLinked ? __('Unlink sides') : __('Link sides');
  return /*#__PURE__*/_jsx(Tooltip, {
    text: label,
    children: /*#__PURE__*/_jsx(View, {
      className: className,
      children: /*#__PURE__*/_jsx(Button, {
        ...buttonProps,
        size: "small",
        icon: isLinked ? link : linkOff,
        iconSize: 24,
        "aria-label": label,
        ref: forwardedRef
      })
    })
  });
};
const ConnectedBorderBoxControlLinkedButton = contextConnect(BorderBoxControlLinkedButton, 'BorderBoxControlLinkedButton');
export default ConnectedBorderBoxControlLinkedButton;
//# sourceMappingURL=component.js.map
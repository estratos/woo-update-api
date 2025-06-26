/**
 * WordPress dependencies
 */
import { useState, flushSync } from '@wordpress/element';
export function useTemporaryFocusVisibleFix({
  onBlur: onBlurProp
}) {
  const [focusVisible, setFocusVisible] = useState(false);
  return {
    'data-focus-visible': focusVisible || undefined,
    onFocusVisible: () => {
      flushSync(() => setFocusVisible(true));
    },
    onBlur: event => {
      onBlurProp?.(event);
      setFocusVisible(false);
    }
  };
}
//# sourceMappingURL=use-temporary-focus-visible-fix.js.map
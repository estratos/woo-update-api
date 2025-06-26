/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import CustomSelectControl from '../custom-select-control';
import { parseQuantityAndUnitFromRawValue } from '../unit-control';
import { getCommonSizeUnit, isSimpleCssValue } from './utils';
import { jsx as _jsx } from "react/jsx-runtime";
const DEFAULT_OPTION = {
  key: 'default',
  name: __('Default'),
  value: undefined
};
const CUSTOM_OPTION = {
  key: 'custom',
  name: __('Custom')
};
const FontSizePickerSelect = props => {
  var _options$find;
  const {
    __next40pxDefaultSize,
    fontSizes,
    value,
    disableCustomFontSizes,
    size,
    onChange,
    onSelectCustom
  } = props;
  const areAllSizesSameUnit = !!getCommonSizeUnit(fontSizes);
  const options = [DEFAULT_OPTION, ...fontSizes.map(fontSize => {
    let hint;
    if (areAllSizesSameUnit) {
      const [quantity] = parseQuantityAndUnitFromRawValue(fontSize.size);
      if (quantity !== undefined) {
        hint = String(quantity);
      }
    } else if (isSimpleCssValue(fontSize.size)) {
      hint = String(fontSize.size);
    }
    return {
      key: fontSize.slug,
      name: fontSize.name || fontSize.slug,
      value: fontSize.size,
      hint
    };
  }), ...(disableCustomFontSizes ? [] : [CUSTOM_OPTION])];
  const selectedOption = value ? (_options$find = options.find(option => option.value === value)) !== null && _options$find !== void 0 ? _options$find : CUSTOM_OPTION : DEFAULT_OPTION;
  return /*#__PURE__*/_jsx(CustomSelectControl, {
    __next40pxDefaultSize: __next40pxDefaultSize,
    className: "components-font-size-picker__select",
    label: __('Font size'),
    hideLabelFromVision: true,
    describedBy: sprintf(
    // translators: %s: Currently selected font size.
    __('Currently selected font size: %s'), selectedOption.name),
    options: options,
    value: selectedOption,
    showSelectedHint: true,
    onChange: ({
      selectedItem
    }) => {
      if (selectedItem === CUSTOM_OPTION) {
        onSelectCustom();
      } else {
        onChange(selectedItem.value);
      }
    },
    size: size
  });
};
export default FontSizePickerSelect;
//# sourceMappingURL=font-size-picker-select.js.map
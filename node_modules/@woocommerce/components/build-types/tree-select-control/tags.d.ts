export default Tags;
/**
 * A list of tags to display selected items.
 *
 * @param {Object}   props                    The component props
 * @param {Object[]} [props.tags=[]]          The tags
 * @param {Function} props.onChange           The method called when a tag is removed
 * @param {boolean}  props.disabled           True if the plugin is disabled
 * @param {number}   [props.maxVisibleTags=0] The maximum number of tags to show. 0 or less than 0 evaluates to "Show All".
 */
declare function Tags({ tags, disabled, maxVisibleTags, onChange, }: {
    tags?: Object[] | undefined;
    onChange: Function;
    disabled: boolean;
    maxVisibleTags?: number | undefined;
}): JSX.Element | null;
//# sourceMappingURL=tags.d.ts.map
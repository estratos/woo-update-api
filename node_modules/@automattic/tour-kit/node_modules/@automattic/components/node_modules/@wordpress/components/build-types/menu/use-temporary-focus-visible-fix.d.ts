export declare function useTemporaryFocusVisibleFix({ onBlur: onBlurProp, }: {
    onBlur?: React.FocusEventHandler<HTMLDivElement>;
}): {
    'data-focus-visible': true | undefined;
    onFocusVisible: () => void;
    onBlur: React.FocusEventHandler<HTMLDivElement>;
};
//# sourceMappingURL=use-temporary-focus-visible-fix.d.ts.map
import { Options } from './types';
/**
 * Request an option value.
 *
 * @param {string} name - Option name
 */
export declare function getOption(name: string): Generator<{
    type: "RECEIVE_OPTIONS";
    options: Options;
} | {
    type: "SET_REQUESTING_ERROR";
    error: unknown;
    name: string;
} | {
    type: string;
    optionName: string;
}, void, Options>;
//# sourceMappingURL=resolvers.d.ts.map
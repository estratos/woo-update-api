"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.Level = void 0;
/**
 * External dependencies
 */
const element_1 = require("@wordpress/element");
/**
 * Context container for heading level. We start at 2 because the `h1` is defined in <Header />
 *
 * See https://medium.com/@Heydon/managing-heading-levels-in-design-systems-18be9a746fa3
 */
const Level = (0, element_1.createContext)(2);
exports.Level = Level;

"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
/**
 * External dependencies
 */
const element_1 = require("@wordpress/element");
const prop_types_1 = __importDefault(require("prop-types"));
class ScrollTo extends element_1.Component {
    constructor(props) {
        super(props);
        this.scrollTo = this.scrollTo.bind(this);
    }
    componentDidMount() {
        setTimeout(this.scrollTo, 250);
    }
    scrollTo() {
        const { offset } = this.props;
        if (this.ref.current && this.ref.current.offsetTop) {
            window.scrollTo(0, this.ref.current.offsetTop + parseInt(offset, 10));
        }
        else {
            setTimeout(this.scrollTo, 250);
        }
    }
    render() {
        const { children } = this.props;
        this.ref = (0, element_1.createRef)();
        return (0, element_1.createElement)("span", { ref: this.ref }, children);
    }
}
ScrollTo.propTypes = {
    /**
     * The offset from the top of the component.
     */
    offset: prop_types_1.default.string,
};
ScrollTo.defaultProps = {
    offset: '0',
};
exports.default = ScrollTo;

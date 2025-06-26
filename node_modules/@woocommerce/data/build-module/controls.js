/**
 * External dependencies
 */
import { controls as dataControls } from '@wordpress/data-controls';
import apiFetch from '@wordpress/api-fetch';
export const fetchWithHeaders = (options) => {
    return {
        type: 'FETCH_WITH_HEADERS',
        options,
    };
};
const controls = {
    ...dataControls,
    FETCH_WITH_HEADERS(action) {
        return apiFetch({ ...action.options, parse: false })
            .then((response) => {
            return Promise.all([
                response.headers,
                response.status,
                response.json(),
            ]);
        })
            .then(([headers, status, data]) => ({
            headers,
            status,
            data,
        }))
            .catch((response) => {
            return response.json().then((data) => {
                throw data;
            });
        });
    },
};
export default controls;

export const isRestApiError = (error) => error.code !== undefined &&
    error.message !== undefined;

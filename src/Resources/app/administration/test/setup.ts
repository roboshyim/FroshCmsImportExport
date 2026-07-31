/**
 * The Administration exposes itself through the global `Shopware` object at runtime. Jest runs the plugin code
 * outside the Administration, so the small surface this plugin builds on is stubbed here.
 */
class ApiServiceStub {
    httpClient: unknown;

    loginService: unknown;

    apiEndpoint: string;

    contentType: string;

    name = '';

    constructor(httpClient: unknown, loginService: unknown, apiEndpoint: string, contentType = 'application/json') {
        this.httpClient = httpClient;
        this.loginService = loginService;
        this.apiEndpoint = apiEndpoint;
        this.contentType = contentType;
    }

    getBasicHeaders(additionalHeaders: Record<string, string> = {}): Record<string, string> {
        return {
            Accept: 'application/vnd.api+json',
            Authorization: 'Bearer test-token',
            'Content-Type': this.contentType,
            ...additionalHeaders,
        };
    }

    static handleResponse<T>(response: { data: T }): T {
        return response.data;
    }
}

Object.assign(globalThis, {
    Shopware: {
        Classes: { ApiService: ApiServiceStub },
        Component: { register: jest.fn(), override: jest.fn() },
        Application: { addServiceProvider: jest.fn(), getContainer: jest.fn() },
        Locale: { extend: jest.fn() },
        Mixin: { getByName: jest.fn() },
    },
});

export {};

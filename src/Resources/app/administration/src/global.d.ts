/**
 * Minimal ambient declarations for the parts of the global `Shopware` object this plugin uses.
 * The Administration does not publish its types to plugins, so they are declared locally.
 */
import type { AxiosInstance, AxiosResponse } from 'axios';

declare global {
    class ShopwareApiService {
        constructor(httpClient: AxiosInstance, loginService: unknown, apiEndpoint: string, contentType?: string);

        httpClient: AxiosInstance;

        name: string;

        getBasicHeaders(additionalHeaders?: Record<string, string>): Record<string, string>;

        static handleResponse<T>(response: AxiosResponse<T>): T;
    }

    const Shopware: {
        Component: {
            override(name: string, config: Record<string, unknown>): void;
            register(name: string, config: Record<string, unknown>): void;
        };
        Application: {
            addServiceProvider(name: string, provider: (container: Record<string, unknown>) => unknown): void;
            getContainer(name: string): Record<string, unknown> & { httpClient: AxiosInstance };
        };
        Locale: {
            extend(localeName: string, messages: Record<string, unknown>): boolean | string;
        };
        Classes: {
            ApiService: typeof ShopwareApiService;
        };
        Mixin: {
            getByName(name: string): unknown;
        };
    };
}

export {};

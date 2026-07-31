/**
 * Jest setup for the Administration extension of this plugin.
 *
 * Only plain TypeScript is covered here. The `sw-cms-list` override and the import modal are Twig-templated
 * Shopware components that need the Administration component factory to render, so they are verified through
 * the Administration itself rather than in isolation.
 */
module.exports = {
    preset: 'ts-jest',
    testEnvironment: 'jsdom',
    rootDir: 'src/Resources/app/administration',
    testMatch: ['<rootDir>/**/*.spec.ts'],
    setupFilesAfterEnv: ['<rootDir>/test/setup.ts'],
    transform: {
        '^.+\\.ts$': [
            'ts-jest',
            {
                tsconfig: {
                    target: 'es2020',
                    module: 'commonjs',
                    esModuleInterop: true,
                    strict: true,
                    types: [
                        'jest',
                        'node',
                    ],
                },
            },
        ],
    },
};

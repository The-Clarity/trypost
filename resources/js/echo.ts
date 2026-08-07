import { configureEcho } from '@laravel/echo-vue';

export type BroadcastingConfig = {
    key: string;
    host: string;
    port: number;
    scheme: 'http' | 'https';
};

export const configureRuntimeEcho = (config: BroadcastingConfig): void => {
    configureEcho({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
};

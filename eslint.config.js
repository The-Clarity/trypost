import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import prettier from 'eslint-config-prettier';
import vue from 'eslint-plugin-vue';

export default defineConfigWithVueTs(
    vue.configs['flat/essential'],
    vueTsConfigs.recommended,
    {
        ignores: [
            'vendor',
            'node_modules',
            'public',
            'bootstrap/ssr',
            'tailwind.config.js',
            'resources/js/components/ui/*',
            // Wayfinder regenerates these on every build with import order
            // matching PHP file scan, not alphabetical. Excluding them avoids
            // a perpetual fight between the generator and import/order.
            'resources/js/actions/**',
            'resources/js/routes/**',
        ],
    },
    {
        rules: {
            'vue/multi-word-component-names': 'off',
            '@typescript-eslint/no-explicit-any': 'off',
            // prettier-plugin-organize-imports is the single import-order owner.
        },
    },
    prettier,
);

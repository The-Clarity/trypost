<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconShieldCheck } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import AuthCardLayout from '@/layouts/auth/AuthCardLayout.vue';
import { approve, deny } from '@/routes/passport/authorizations';

type Scope = {
    id: string;
    description: string;
};

type WorkspaceOption = {
    id: string;
    name: string;
};

const props = defineProps<{
    client: {
        id: string;
        name: string;
    };
    user: {
        email: string;
    };
    workspaces: WorkspaceOption[];
    selectedWorkspaceId: string;
    scopes: Scope[];
    authToken: string;
    state: string;
}>();

const workspaceId = ref(props.selectedWorkspaceId || props.workspaces[0]?.id || '');
const approving = ref(false);
const csrfToken =
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';

const scopeLabel = (scope: Scope): string =>
    scope.id === 'mcp:use'
        ? trans('mcp.authorize.scope_mcp_use')
        : scope.description;

const onApproveSubmit = (): void => {
    approving.value = true;

    // Popup MCP clients expect the window to close after the redirect.
    window.setTimeout(() => {
        const checkRedirect = window.setInterval(() => {
            if (
                !window.location.href.includes('/oauth/authorize') ||
                window.location.search.includes('code=') ||
                window.location.search.includes('error=')
            ) {
                window.clearInterval(checkRedirect);
                window.close();
            }
        }, 100);

        window.setTimeout(() => {
            window.clearInterval(checkRedirect);
            window.close();
        }, 5000);
    }, 200);
};

const onDenySubmit = (): void => {
    window.setTimeout(() => {
        window.close();
    }, 200);
};
</script>

<template>
    <AuthCardLayout
        :title="trans('mcp.authorize.heading', { client: client.name })"
        :description="`${trans('mcp.authorize.intro')} ${trans('mcp.authorize.intro_capability')}`"
    >
        <Head :title="trans('mcp.authorize.page_title')" />

        <div class="flex flex-col gap-6">
            <div class="flex justify-center">
                <div
                    class="flex size-12 items-center justify-center rounded-full border-2 border-foreground bg-card"
                >
                    <IconShieldCheck class="size-6 text-foreground" />
                </div>
            </div>

            <div class="space-y-4 rounded-xl border-2 border-foreground bg-muted/40 p-4">
                <div class="space-y-1.5">
                    <p class="text-sm text-muted-foreground">
                        {{ $t('mcp.authorize.logged_in_as') }}
                    </p>
                    <p class="font-medium" dusk="mcp-authorize-email">
                        {{ user.email }}
                    </p>
                </div>

                <div v-if="workspaces.length > 0" class="space-y-1.5">
                    <Label for="workspace_id">{{
                        $t('mcp.authorize.workspace')
                    }}</Label>
                    <div
                        class="w-full [&_[data-slot=native-select-wrapper]]:w-full"
                    >
                        <NativeSelect
                            id="workspace_id"
                            v-model="workspaceId"
                            name="workspace_id"
                            form="authorizeForm"
                            class="w-full"
                            dusk="mcp-authorize-workspace"
                        >
                            <NativeSelectOption
                                v-for="workspace in workspaces"
                                :key="workspace.id"
                                :value="workspace.id"
                            >
                                {{ workspace.name }}
                            </NativeSelectOption>
                        </NativeSelect>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ $t('mcp.authorize.workspace_scope') }}
                    </p>
                </div>
            </div>

            <div v-if="scopes.length > 0" class="space-y-2">
                <p class="text-sm font-medium">
                    {{ $t('mcp.authorize.permissions') }}
                </p>
                <ul class="space-y-2">
                    <li
                        v-for="scope in scopes"
                        :key="scope.id"
                        class="flex items-start gap-2"
                    >
                        <div
                            class="mt-0.5 rounded-full bg-primary/10 p-1"
                        >
                            <div
                                class="size-1.5 rounded-full bg-primary"
                            />
                        </div>
                        <span class="text-sm text-muted-foreground">
                            {{ scopeLabel(scope) }}
                        </span>
                    </li>
                </ul>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:justify-start">
                <!-- Native forms: Passport redirects off-site; Inertia visits would break the OAuth popup. -->
                <form
                    id="authorizeForm"
                    method="POST"
                    :action="approve.url()"
                    class="flex-1"
                    @submit="onApproveSubmit"
                >
                    <input type="hidden" name="_token" :value="csrfToken" />
                    <input type="hidden" name="state" :value="state" />
                    <input type="hidden" name="client_id" :value="client.id" />
                    <input
                        type="hidden"
                        name="auth_token"
                        :value="authToken"
                    />
                    <Button
                        type="submit"
                        class="w-full"
                        :loading="approving"
                        dusk="mcp-authorize-approve"
                    >
                        {{
                            approving
                                ? $t('mcp.authorize.approving')
                                : $t('mcp.authorize.approve')
                        }}
                    </Button>
                </form>

                <form
                    method="POST"
                    :action="deny.url()"
                    class="flex-1"
                    @submit="onDenySubmit"
                >
                    <input type="hidden" name="_token" :value="csrfToken" />
                    <input type="hidden" name="_method" value="DELETE" />
                    <input type="hidden" name="state" :value="state" />
                    <input type="hidden" name="client_id" :value="client.id" />
                    <input
                        type="hidden"
                        name="auth_token"
                        :value="authToken"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        class="w-full"
                        dusk="mcp-authorize-cancel"
                    >
                        {{ $t('mcp.authorize.cancel') }}
                    </Button>
                </form>
            </div>
        </div>
    </AuthCardLayout>
</template>

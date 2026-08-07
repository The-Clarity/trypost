<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\LinkedInPageOAuthAttempts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class LinkedInController extends SocialController
{
    protected SocialPlatform $platform = SocialPlatform::LinkedInPage;

    public function __construct(private readonly LinkedInPageOAuthAttempts $oauthAttempts) {}

    public function connectPage(Request $request): Response
    {
        if (! $this->pageConnectionConfigured()) {
            abort(Response::HTTP_FORBIDDEN, 'LinkedIn Page is currently unavailable.');
        }

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        $state = $this->oauthAttempts->issue(
            $request,
            LinkedInPageOAuthAttempts::PHASE_OAUTH,
            $workspace->id,
        );

        return Inertia::location(
            Socialite::driver('linkedin-page')
                ->stateless()
                ->setScopes(config('trypost.platforms.linkedin-page.scopes'))
                ->with(['state' => $state])
                ->redirect()
                ->getTargetUrl()
        );
    }

    public function callback(Request $request): InertiaResponse
    {
        $attempt = $this->oauthAttempts->consume(
            $request,
            LinkedInPageOAuthAttempts::PHASE_OAUTH,
            $request->query('state'),
        );
        $workspaceId = data_get($attempt, 'workspace_id');

        if (! is_string($workspaceId) || ! $this->pageConnectionConfigured()) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        $workspace = Workspace::find($workspaceId);

        if (! $workspace || ! $request->user()->can('manageAccounts', $workspace)) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        try {
            $socialUser = Socialite::driver('linkedin-page')->stateless()->user();
            $pending = [
                'workspace_id' => $workspace->id,
                'token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
                'expires_in' => $socialUser->expiresIn,
                'approved_scopes' => $socialUser->approvedScopes ?? [],
                'organizations' => $this->fetchConfiguredOrganization($socialUser->token),
            ];
            $selectionAttempt = $this->oauthAttempts->issue(
                $request,
                LinkedInPageOAuthAttempts::PHASE_SELECTION,
                $workspace->id,
                $pending,
            );

            return Inertia::render('accounts/LinkedInSelect', [
                'attempt' => $selectionAttempt,
                'organizations' => $pending['organizations'],
            ]);
        } catch (\Exception $e) {
            Log::error('LinkedIn Page OAuth error', [
                'error' => $e->getMessage(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }

    public function select(Request $request): InertiaResponse
    {
        $attempt = $this->oauthAttempts->consume(
            $request,
            LinkedInPageOAuthAttempts::PHASE_SELECTION,
            $request->input('attempt'),
        );
        $workspaceId = data_get($attempt, 'workspace_id');
        $pending = data_get($attempt, 'payload');

        if (! is_string($workspaceId)
            || ! is_array($pending)
            || ! hash_equals($workspaceId, (string) data_get($pending, 'workspace_id'))) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        $validator = Validator::make($request->all(), [
            'type' => ['required', Rule::in(['organization'])],
            'organization_id' => ['required'],
        ]);

        if ($validator->fails()) {
            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }

        $validated = $validator->validated();
        $workspace = Workspace::find($workspaceId);

        if (! $workspace || ! $request->user()->can('manageAccounts', $workspace)) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        if (! $this->pageConnectionConfigured()) {
            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }

        $organization = $this->resolveConfiguredAdministeredOrganization(
            $pending,
            data_get($validated, 'organization_id'),
        );

        if (! $organization) {
            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }

        try {
            $this->connectOrganization($workspace, $pending, $organization);

            return $this->popupCallback(true, __('accounts.popup_callback.connected'), $this->platform->value);
        } catch (NetworkAlreadyConnectedException) {
            return $this->popupCallback(false, __('accounts.popup_callback.network_taken'), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('LinkedIn Page selection error', [
                'error' => $e->getMessage(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }

    /**
     * Match the chosen organization id against the admin-verified list captured at
     * callback, so a tampered POST cannot connect a company the member does not
     * administer.
     *
     * @param  array<string, mixed>  $pending
     * @return array<string, mixed>|null
     */
    private function resolveConfiguredAdministeredOrganization(array $pending, mixed $organizationId): ?array
    {
        $configuredOrganizationId = SocialAccount::configuredLinkedInPageOrganizationId();

        if ($configuredOrganizationId === null
            || ! hash_equals($configuredOrganizationId, (string) $organizationId)) {
            return null;
        }

        return collect(data_get($pending, 'organizations', []))
            ->first(fn ($organization) => hash_equals(
                $configuredOrganizationId,
                (string) data_get($organization, 'id'),
            ));
    }

    /**
     * The configured company becomes a `linkedin-page` account. The organization
     * data comes from the ACL-verified session list, never the request body; the
     * acting member's personal identity is neither requested nor persisted.
     *
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $organization
     */
    private function connectOrganization(Workspace $workspace, array $pending, array $organization): void
    {
        $organizationId = (string) data_get($organization, 'id');
        $configuredOrganizationId = SocialAccount::configuredLinkedInPageOrganizationId();

        if ($configuredOrganizationId === null || ! hash_equals($configuredOrganizationId, $organizationId)) {
            throw new \LogicException('Attempted to connect an unauthorized LinkedIn Page organization.');
        }

        $workspace->socialAccounts()->updateOrCreate(
            [
                'platform' => SocialPlatform::LinkedInPage->value,
                'platform_user_id' => $organizationId,
            ],
            [
                'username' => data_get($organization, 'vanity_name'),
                'display_name' => data_get($organization, 'name'),
                'avatar_url' => uploadFromUrl(data_get($organization, 'logo')),
                'access_token' => $pending['token'],
                'refresh_token' => $pending['refresh_token'],
                'token_expires_at' => $pending['expires_in'] ? now()->addSeconds($pending['expires_in']) : null,
                'scopes' => $this->normalizeScopes($pending['approved_scopes'] ?? []),
                'status' => Status::Connected,
                'error_message' => null,
                'disconnected_at' => null,
                'meta' => [
                    'organization_id' => $organizationId,
                ],
            ],
        );
    }

    /**
     * LinkedIn returns approved scopes comma-joined, but Socialite splits OAuth
     * scopes on space — so the whole CSV lands as a single array element. Re-split
     * on commas to store individual scope tokens.
     *
     * @param  array<int, string>  $approvedScopes
     * @return array<int, string>
     */
    private function normalizeScopes(array $approvedScopes): array
    {
        return array_values(array_unique(array_filter(explode(',', implode(',', $approvedScopes)))));
    }

    /**
     * Return the configured Clarity organization only when the authenticated
     * member administers it. Other administered Pages are never selectable.
     *
     * @return array<int, array{id: mixed, name: string, vanity_name: ?string, logo: ?string}>
     */
    private function fetchConfiguredOrganization(string $accessToken): array
    {
        $configuredOrganizationId = SocialAccount::configuredLinkedInPageOrganizationId();

        if ($configuredOrganizationId === null) {
            return [];
        }

        $response = Http::withToken($accessToken)
            ->connectTimeout(5)
            ->timeout(10)
            ->get(config('trypost.platforms.linkedin-page.api').'/v2/organizationAcls', [
                'q' => 'roleAssignee',
                'role' => 'ADMINISTRATOR',
                'projection' => '(elements*(organization~(id,localizedName,vanityName,logoV2(original~:playableStreams))))',
            ]);

        if ($response->failed()) {
            Log::error('LinkedIn Page organizations fetch error', [
                'status' => $response->status(),
            ]);

            return [];
        }

        $organizations = [];

        foreach (data_get($response->json(), 'elements', []) as $element) {
            $org = data_get($element, 'organization~');

            if ($org && hash_equals($configuredOrganizationId, (string) data_get($org, 'id'))) {
                $organizations[] = [
                    'id' => data_get($org, 'id'),
                    'name' => data_get($org, 'localizedName', 'Unknown'),
                    'vanity_name' => data_get($org, 'vanityName'),
                    'logo' => data_get($org, 'logoV2.original~.elements.0.identifiers.0.identifier'),
                ];
            }
        }

        return $organizations;
    }

    private function pageConnectionConfigured(): bool
    {
        return $this->platform->isEnabled()
            && SocialAccount::configuredLinkedInPageOrganizationId() !== null;
    }
}

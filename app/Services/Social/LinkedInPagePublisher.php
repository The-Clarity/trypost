<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\LinkedInPublishException;
use App\Models\SocialAccount;

/**
 * Publishes posts to a LinkedIn company page on behalf of an administering member.
 */
class LinkedInPagePublisher extends AbstractLinkedInPublisher
{
    protected function platform(): Platform
    {
        return Platform::LinkedInPage;
    }

    protected function authorUrn(): string
    {
        $configuredOrganizationId = SocialAccount::configuredLinkedInPageOrganizationId();

        if ($configuredOrganizationId === null) {
            throw new LinkedInPublishException(
                userMessage: 'LinkedIn Page organization ID is not configured',
                category: ErrorCategory::Permission,
            );
        }

        $organizationId = $this->account->meta['organization_id'] ?? null;

        if (! $organizationId) {
            throw new LinkedInPublishException(
                userMessage: 'LinkedIn Page organization ID not configured',
                category: ErrorCategory::Permission,
            );
        }

        if (! hash_equals($configuredOrganizationId, (string) $organizationId)
            || ! hash_equals($configuredOrganizationId, (string) $this->account->platform_user_id)) {
            throw new LinkedInPublishException(
                userMessage: 'LinkedIn Page is not authorized for this deployment',
                category: ErrorCategory::Permission,
            );
        }

        return "urn:li:organization:{$configuredOrganizationId}";
    }

    protected function postUrl(?string $postId): ?string
    {
        if (! $postId) {
            return null;
        }

        return $this->account->username
            ? "https://www.linkedin.com/company/{$this->account->username}/posts/"
            : "https://www.linkedin.com/feed/update/{$postId}";
    }
}

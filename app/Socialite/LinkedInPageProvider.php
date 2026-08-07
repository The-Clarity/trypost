<?php

declare(strict_types=1);

namespace App\Socialite;

use Laravel\Socialite\Two\LinkedInProvider as BaseLinkedInProvider;
use Laravel\Socialite\Two\User;
use SocialiteProviders\Manager\ConfigTrait;
use SocialiteProviders\Manager\Contracts\OAuth2\ProviderInterface;

/**
 * LinkedIn's Community Management app authorizes Page access through its
 * organization ACL. OAuth therefore exchanges only the authorization code;
 * it never fetches or materializes the acting member's personal profile.
 */
class LinkedInPageProvider extends BaseLinkedInProvider implements ProviderInterface
{
    use ConfigTrait;

    public const IDENTIFIER = 'LINKEDIN_PAGE';

    /** @var array<int, string> */
    protected $scopes = [];

    /** @return array<string, mixed> */
    protected function getUserByToken($token): array
    {
        return [];
    }

    /** @param array<string, mixed> $user */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw([]);
    }
}

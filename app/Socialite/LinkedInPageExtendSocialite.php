<?php

declare(strict_types=1);

namespace App\Socialite;

use SocialiteProviders\Manager\SocialiteWasCalled;

class LinkedInPageExtendSocialite
{
    public function handle(SocialiteWasCalled $socialiteWasCalled): void
    {
        $socialiteWasCalled->extendSocialite('linkedin-page', LinkedInPageProvider::class);
    }
}

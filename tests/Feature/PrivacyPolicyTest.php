<?php

namespace Tests\Feature;

use Tests\TestCase;

class PrivacyPolicyTest extends TestCase
{
    public function test_privacy_policy_is_publicly_available(): void
    {
        $this->get('/privacy-policy')
            ->assertOk()
            ->assertSee('Politique de confidentialité')
            ->assertSee('JP2 Création')
            ->assertSee('Martin Sols')
            ->assertDontSee('crm-shell-config', false);
    }

    public function test_french_privacy_policy_alias_is_publicly_available(): void
    {
        $this->get('/politique-confidentialite')
            ->assertOk()
            ->assertSee('Politique de confidentialité')
            ->assertSee('contact@jp2creation.fr');
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_guest_is_redirected_to_login_from_crm_home(): void
    {
        $this->get('/')
            ->assertRedirect('/login');
    }

    public function test_login_page_loads(): void
    {
        $response = $this->get('/login')
            ->assertOk()
            ->assertSee('Web APK')
            ->assertSee('Android')
            ->assertSee('iPhone')
            ->assertSee('iPad')
            ->assertSee('Martin.Sols.pkg')
            ->assertSee('Connexion rapide')
            ->assertDontSee('Application Android')
            ->assertDontSee('Application Mac')
            ->assertDontSee('Application web')
            ->assertDontSee('Connexion équipe')
            ->assertDontSee('Sécurité anti-robot')
            ->assertDontSee('Connexion HUB');

        $html = (string) $response->getContent();
        $previousLibxmlState = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlState);

        $appInstall = (new \DOMXPath($document))->query('//*[@data-login-app-install]')->item(0);

        $this->assertNotNull($appInstall);
        $this->assertSame('main', strtolower($appInstall->parentNode?->nodeName ?? ''));
        $this->assertStringContainsString('background: transparent;', $html);
        $this->assertStringContainsString('border: 0;', $html);
        $this->assertStringContainsString('min-width: 158px;', $html);
        $this->assertStringContainsString('min-height: 48px;', $html);
        $this->assertStringContainsString('border-radius: 10px;', $html);
        $this->assertStringContainsString('const isIpad', $html);
        $this->assertStringContainsString("label: 'Web APK'", $html);
        $this->assertStringContainsString("label: 'Android'", $html);
        $this->assertStringContainsString('Ajouter sur', $html);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_login_page_uses_shared_crm_theme_config(): void
    {
        config([
            'crm_theme.colors.primary' => '#123456',
            'crm_theme.colors.primary_dark' => '#0f2233',
            'crm_theme.colors.accent' => '#abcdef',
        ]);

        $html = $this->get('/login')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="theme-color" content="#123456"', $html);
        $this->assertStringContainsString('style="--theme-primary: 18 52 86;', $html);
        $this->assertStringContainsString('--theme-accent: 171 205 239;', $html);
        $this->assertStringContainsString('--primary: var(--theme-primary-color);', $html);
        $this->assertStringNotContainsString('--primary: #a50034;', $html);
    }

    public function test_login_page_can_remember_the_user_email_without_storing_the_password(): void
    {
        $html = $this->get('/login')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('autocomplete="on" data-login-form', $html);
        $this->assertStringContainsString('autocomplete="username"', $html);
        $this->assertStringContainsString('autocomplete="current-password"', $html);
        $this->assertStringContainsString('data-login-email', $html);
        $this->assertStringContainsString('data-login-password', $html);
        $this->assertStringContainsString('data-native-login', $html);
        $this->assertStringContainsString('/api/mobile/token', $html);
        $this->assertStringContainsString('/api/mobile/web-session', $html);
        $this->assertStringContainsString('saveMobileSession', $html);
        $this->assertStringContainsString('authenticateSavedMobileSession', $html);
        $this->assertStringContainsString('data-login-remember checked', $html);
        $this->assertStringContainsString('martin-sols:login:remembered-email', $html);
        $this->assertStringContainsString('window.localStorage.setItem(storageKey, normalizedEmail)', $html);
        $this->assertStringContainsString('window.localStorage.removeItem(storageKey)', $html);
        $this->assertStringContainsString('Rester connect', $html);
        $this->assertStringNotContainsString('connectÃ', $html);
        $this->assertStringNotContainsString('localStorage.setItem(storageKey, password', $html);
        $this->assertStringNotContainsString('localStorage.setItem(storageKey, currentPassword', $html);
    }

    public function test_authenticated_user_can_refresh_legacy_crm_dashboard_route(): void
    {
        $this->actingAs(User::factory()->make())
            ->get('/dashboard/crm')
            ->assertRedirect('/');

        $this->actingAs(User::factory()->make())
            ->followingRedirects()
            ->get('/dashboard/crm')
            ->assertOk()
            ->assertViewIs('crm');
    }
}

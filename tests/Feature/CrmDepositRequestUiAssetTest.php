<?php

namespace Tests\Feature;

use Tests\TestCase;

class CrmDepositRequestUiAssetTest extends TestCase
{
    public function test_deposit_request_module_uses_session_csrf_for_mutations(): void
    {
        $source = (string) file_get_contents(base_path('Modules/CrmDepositRequests/resources/assets/crm-demandes-acompte.js'));
        $public = (string) file_get_contents(public_path('modules/crm-deposit-requests/crm-demandes-acompte.js'));
        $asset = str_replace("'", '"', $source);

        $this->assertSame($source, $public);
        $this->assertStringContainsString('const api = "/api/demandes-acompte"', $asset);
        $this->assertStringNotContainsString('/api/demandes-acompte.php', $asset);
        $this->assertStringContainsString('function csrfToken()', $asset);
        $this->assertStringContainsString('credentials: "same-origin"', $asset);
        $this->assertStringContainsString('"X-CSRF-TOKEN": csrfToken()', $asset);
        $this->assertStringContainsString('response.json().catch(() => ({}))', $asset);
        $this->assertStringContainsString('Session expiree, rechargez la page puis recommencez.', $asset);
        $this->assertStringContainsString('save_request', $asset);
        $this->assertStringContainsString('validate_request', $asset);
        $this->assertStringContainsString('delete_request', $asset);
    }
}

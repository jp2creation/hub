<?php

namespace Tests\Feature;

use Tests\TestCase;

class HubAssistantFrontendSourceTest extends TestCase
{
    public function test_shell_installs_hub_assistant(): void
    {
        $shell = (string) file_get_contents(resource_path('frontend/crm/shell.ts'));
        $assistant = (string) file_get_contents(resource_path('frontend/crm/assistant.ts'));
        $nativeShell = (string) file_get_contents(resource_path('frontend/crm/layout/native-shell.ts'));
        $styles = (string) file_get_contents(resource_path('frontend/crm/styles/shell.css'));
        $routes = (string) file_get_contents(base_path('Modules/CrmCore/routes/web.php'));

        $this->assertStringContainsString("import { installHubAssistant } from './assistant';", $shell);
        $this->assertStringContainsString('installHubAssistant();', $shell);
        $this->assertStringContainsString('/api/hub-assistant/message', $assistant);
        $this->assertStringContainsString('data-hub-assistant-form', $assistant);
        $this->assertStringContainsString('répondre à une question courante', $assistant);
        $this->assertStringContainsString('Aide et navigation', $assistant);
        $this->assertStringContainsString('Posez une question ou recherchez une page', $assistant);
        $this->assertStringContainsString("document.body.classList.toggle('hub-assistant-open', isOpen);", $assistant);
        $this->assertStringContainsString('window.MartinSolsHubAssistant', $assistant);
        $this->assertStringContainsString('data-hub-assistant-open', $nativeShell);
        $this->assertStringContainsString('Assistant HUB</strong><small>Navigation rapide', $nativeShell);
        $this->assertStringNotContainsString('data-hub-assistant-toggle', $assistant);
        $this->assertStringContainsString('.hub-assistant-panel', $styles);
        $this->assertStringContainsString('.hub-assistant-panel[hidden]', $styles);
        $this->assertStringContainsString('body.hub-assistant-open .hub-assistant-panel', $styles);
        $this->assertStringContainsString('height: 100dvh;', $styles);
        $this->assertStringContainsString('font-size: 1rem;', $styles);
        $this->assertStringContainsString('font-weight: 650;', $styles);
        $this->assertStringContainsString("Route::post('/api/hub-assistant/message'", $routes);
    }
}

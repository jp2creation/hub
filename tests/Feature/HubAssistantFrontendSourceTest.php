<?php

namespace Tests\Feature;

use Tests\TestCase;

class HubAssistantFrontendSourceTest extends TestCase
{
    public function test_shell_installs_hub_assistant(): void
    {
        $shell = (string) file_get_contents(resource_path('frontend/crm/shell.ts'));
        $assistant = (string) file_get_contents(resource_path('frontend/crm/assistant.ts'));
        $styles = (string) file_get_contents(resource_path('frontend/crm/styles/shell.css'));
        $routes = (string) file_get_contents(base_path('Modules/CrmCore/routes/web.php'));

        $this->assertStringContainsString("import { installHubAssistant } from './assistant';", $shell);
        $this->assertStringContainsString('installHubAssistant();', $shell);
        $this->assertStringContainsString('/api/hub-assistant/message', $assistant);
        $this->assertStringContainsString('data-hub-assistant-form', $assistant);
        $this->assertStringContainsString('.hub-assistant-panel', $styles);
        $this->assertStringContainsString("Route::post('/api/hub-assistant/message'", $routes);
    }
}

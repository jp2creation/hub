<?php

namespace Tests\Feature;

use Tests\TestCase;

class CrmOpenApiDocumentationTest extends TestCase
{
    public function test_openapi_documents_current_crm_endpoints_and_missing_template_apis(): void
    {
        $path = resource_path('openapi/crm.yaml');

        $this->assertFileExists($path);

        $openApi = (string) file_get_contents($path);

        foreach ([
            '/api/dashboard:',
            '/api/administration:',
            '/api/reservations:',
            '/api/reservations/bootstrap:',
            '/api/reservations/users:',
            '/api/reservations/vehicles:',
            '/api/equipment-rentals:',
            '/api/equipment-rentals/bootstrap:',
            '/api/equipment-rentals/users:',
            '/api/equipment-rentals/items:',
            '/api/equipment-rentals/categories:',
            '/api/conges:',
            '/api/controle-caisse:',
            '/api/demandes-acompte:',
            '/api/remise-cheques:',
            '/api/documents:',
            '/api/pages:',
            '/api/equipes:',
            '/api/tournees-representants:',
            '/documents/file/{document}:',
            '/api/mobile/token:',
            '/api/mobile/refresh:',
            '/api/mobile/me:',
            '/api/mobile/native-session:',
            '/api/mobile/web-session:',
            '/api/mobile/logout:',
        ] as $route) {
            $this->assertStringContainsString($route, $openApi);
        }

        $this->assertStringNotContainsString('.php:', $openApi);
        $this->assertStringNotContainsString('Ancien alias .php', $openApi);
        $this->assertStringNotContainsString('Mutation legacy .php', $openApi);
        $this->assertStringNotContainsString('Meme contrat que /api/documents.', $openApi);

        foreach ([
            'create_reservation',
            'delete_reservation',
            'create_rental',
            'delete_rental',
            'save_request',
            'validate_request',
            'save_leave',
            'delete_leave',
            'save_remittance',
            'detect_check_ocr',
        ] as $action) {
            $this->assertStringContainsString($action, $openApi);
        }

        $this->assertStringContainsString('chatEndpoint: absent', $openApi);
        $this->assertStringContainsString('newsEndpoint: absent', $openApi);
        $this->assertStringContainsString('/api/chat:', $openApi);
        $this->assertStringContainsString('/api/news:', $openApi);
    }
}

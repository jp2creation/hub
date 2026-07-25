<?php

namespace Tests\Feature;

use App\Models\CrmNotificationLog;
use App\Models\CrmSite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CrmCore\Http\Controllers\MobileAuthController;
use Modules\CrmCore\Services\CrmNotificationService;
use Modules\CrmCore\Services\MobileAuthService;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CrmPlatformFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_crm_models_are_audited_with_activitylog(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $site = CrmSite::query()->create([
            'name' => 'Palissy',
            'active' => true,
            'morning_start' => '07:30',
            'morning_end' => '12:00',
            'afternoon_start' => '13:30',
            'afternoon_end' => '17:30',
        ]);

        $site->update(['name' => 'Palissy Centre']);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'crm',
            'event' => 'created',
            'subject_type' => CrmSite::class,
            'subject_id' => $site->id,
            'causer_type' => User::class,
            'causer_id' => $user->id,
        ]);

        $activity = Activity::query()
            ->where('event', 'updated')
            ->where('subject_type', CrmSite::class)
            ->where('subject_id', $site->id)
            ->firstOrFail();

        $properties = $activity->properties->toArray();

        $this->assertSame('Palissy Centre', $properties['attributes']['name']);
        $this->assertSame('Palissy', $properties['old']['name']);
        $this->assertArrayNotHasKey('updated_at', $properties['attributes']);
    }

    public function test_crm_notification_service_records_database_and_pwa_ready_notifications(): void
    {
        $user = User::factory()->create(['email' => 'jp@example.test']);

        app(CrmNotificationService::class)->notify($user, 'system_alert', [
            'title' => 'Alerte HUB',
            'body' => 'Controle a effectuer.',
            'actionUrl' => '/',
        ], ['database', 'pwa']);

        $notification = $user->notifications()->firstOrFail();

        $this->assertSame('Alerte HUB', $notification->data['subject']);
        $this->assertSame('/', $notification->data['pwa']['url']);
        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'database',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'template_key' => 'system_alert',
            'status' => CrmNotificationLog::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'pwa',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'template_key' => 'system_alert',
            'status' => CrmNotificationLog::STATUS_SENT,
        ]);
    }

    public function test_crm_defaults_to_french_locale(): void
    {
        $exampleEnv = (string) file_get_contents(base_path('.env.example'));

        $this->assertSame('fr', config('app.locale'));
        $this->assertSame('fr', config('app.fallback_locale'));
        $this->assertSame('fr_FR', config('app.faker_locale'));
        $this->assertStringContainsString('APP_LOCALE=fr', $exampleEnv);
        $this->assertStringContainsString('CRM_NOTIFICATION_CHANNELS=database,mail,pwa', $exampleEnv);
        $this->assertSame('Identifiants invalides.', __('crm.mobile.invalid_credentials'));
    }

    public function test_mobile_auth_controller_delegates_business_logic_to_service(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(MobileAuthController::class))->getFileName());

        $this->assertStringContainsString(MobileAuthService::class, $source);
        $this->assertStringNotContainsString('Hash::', $source);
        $this->assertStringNotContainsString('RateLimiter::', $source);
        $this->assertStringNotContainsString('Cache::', $source);
        $this->assertLessThan(80, substr_count($source, PHP_EOL));
    }
}

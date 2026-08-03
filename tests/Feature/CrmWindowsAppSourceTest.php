<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CrmWindowsAppSourceTest extends TestCase
{
    public function test_it_ships_a_windows_hub_wrapper_aligned_with_the_native_mobile_bridge(): void
    {
        $root = base_path('mobile/windows');

        $this->assertTrue(File::exists($root.'/package.json'));
        $this->assertTrue(File::exists($root.'/src/main.js'));
        $this->assertTrue(File::exists($root.'/src/preload.js'));
        $this->assertTrue(File::exists($root.'/src/splash.html'));

        $package = json_decode(File::get($root.'/package.json'), true);

        $this->assertSame('fr.martinsols.hub.windows', $package['build']['appId']);
        $this->assertSame('Martin Sols HUB', $package['build']['productName']);
        $this->assertSame('${productName}-${version}-${os}-${arch}-setup.${ext}', $package['build']['nsis']['artifactName']);
        $this->assertSame('${productName}-${version}-${os}-${arch}-portable.${ext}', $package['build']['portable']['artifactName']);
        $this->assertSame('nsis', $package['build']['win']['target'][0]['target']);

        $main = File::get($root.'/src/main.js');
        $preload = File::get($root.'/src/preload.js');
        $splash = File::get($root.'/src/splash.html');

        $this->assertStringContainsString('https://crm.jp2.fr/?mobile_app=1&source=windows_app', $main);
        $this->assertStringContainsString('safeStorage', $main);
        $this->assertStringContainsString('setPermissionRequestHandler', $main);
        $this->assertStringContainsString('raw.githubusercontent.com/jp2creation/hub/main/mobile/releases/martin-sols-update.json', $main);
        $this->assertStringContainsString("contextBridge.exposeInMainWorld('MartinSolsNativeApp'", $preload);
        $this->assertStringContainsString("ipcRenderer.sendSync('martin-sols:get-mobile-auth-status')", $preload);
        $this->assertStringContainsString('martin-sols:native-location-result', $preload);
        $this->assertStringContainsString('martin-sols:native-auth-result', $preload);
        $this->assertStringContainsString('intro', $splash);
    }

    public function test_it_declares_windows_updates_in_the_shared_native_app_manifest(): void
    {
        $manifest = json_decode(File::get(base_path('mobile/releases/martin-sols-update.json')), true);

        $this->assertSame('1.0.0', $manifest['windows']['version']);
        $this->assertSame(1, $manifest['windows']['buildNumber']);
        $this->assertFalse($manifest['windows']['required']);
        $this->assertArrayHasKey('installerUrl', $manifest['windows']);
        $this->assertArrayHasKey('portableUrl', $manifest['windows']);
        $this->assertArrayHasKey('sha256', $manifest['windows']);
        $this->assertArrayHasKey('releaseNotes', $manifest['windows']);
    }
}

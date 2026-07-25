import Cocoa
import WebKit

@main
enum MartinSolsMacMain {
    private static var appDelegate: MacAppDelegate?

    static func main() {
        let application = NSApplication.shared

        appDelegate = MacAppDelegate()
        application.delegate = appDelegate
        application.setActivationPolicy(.regular)
        application.run()
    }
}

final class MacAppDelegate: NSObject, NSApplicationDelegate, WKNavigationDelegate, WKUIDelegate, WKScriptMessageHandler {
    private static let crmUrl = URL(string: "https://crm.jp2.fr/?mobile_app=1&source=mac_app")!
    private static let updateManifestUrl = URL(string: "https://raw.githubusercontent.com/jp2creation/hub/main/mobile/releases/martin-sols-update.json")!
    private static let nativeMessageHandlerName = "martinSolsNativeApp"
    private static let splashDuration: TimeInterval = 5.5
    private static let updateCheckDelay: TimeInterval = 1.5
    private static let titleBarHeight: CGFloat = 46

    private var window: NSWindow!
    private var rootView: NSView!
    private var titleBarView: NSView!
    private var contentView: NSView!
    private var settingsButton: NSButton!
    private var webView: WKWebView!
    private var splashView: NSView?
    private var splashWebView: WKWebView?
    private var updateCheckStarted = false

    func applicationDidFinishLaunching(_ notification: Notification) {
        configureMainMenu()
        configureWindow()
        configureWebView()
        showSplash()
        webView.load(URLRequest(url: Self.crmUrl))

        DispatchQueue.main.asyncAfter(deadline: .now() + Self.splashDuration) { [weak self] in
            self?.hideSplash()
        }
    }

    func applicationShouldTerminateAfterLastWindowClosed(_ sender: NSApplication) -> Bool {
        return true
    }

    func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
        guard message.name == Self.nativeMessageHandlerName, isTrustedCrmPage() else {
            return
        }

        let action: String?

        if let body = message.body as? [String: Any] {
            action = body["action"] as? String
        } else {
            action = message.body as? String
        }

        if action == "checkForUpdates" {
            checkForAppUpdate(notifyWhenCurrent: true)
        }
    }

    func webView(
        _ webView: WKWebView,
        decidePolicyFor navigationAction: WKNavigationAction,
        decisionHandler: @escaping (WKNavigationActionPolicy) -> Void
    ) {
        guard let scheme = navigationAction.request.url?.scheme?.lowercased() else {
            decisionHandler(.cancel)

            return
        }

        decisionHandler((scheme == "http" || scheme == "https") ? .allow : .cancel)
    }

    func webView(
        _ webView: WKWebView,
        createWebViewWith configuration: WKWebViewConfiguration,
        for navigationAction: WKNavigationAction,
        windowFeatures: WKWindowFeatures
    ) -> WKWebView? {
        if navigationAction.targetFrame == nil {
            webView.load(navigationAction.request)
        }

        return nil
    }

    private func configureMainMenu() {
        let mainMenu = NSMenu()
        let appMenuItem = NSMenuItem()
        let editMenuItem = NSMenuItem()

        mainMenu.addItem(appMenuItem)
        mainMenu.addItem(editMenuItem)

        let appMenu = NSMenu()
        let appSettingsItem = NSMenuItem(
            title: "Paramètres de l’app...",
            action: #selector(openAppSettings),
            keyEquivalent: ","
        )
        appSettingsItem.target = self
        appMenu.addItem(appSettingsItem)
        appMenu.addItem(NSMenuItem.separator())
        appMenu.addItem(
            NSMenuItem(
                title: "Quit Martin Sols",
                action: #selector(NSApplication.terminate(_:)),
                keyEquivalent: "q"
            )
        )
        appMenuItem.submenu = appMenu

        let editMenu = NSMenu(title: "Edit")
        editMenu.addItem(NSMenuItem(title: "Undo", action: Selector(("undo:")), keyEquivalent: "z"))
        editMenu.addItem(NSMenuItem(title: "Redo", action: Selector(("redo:")), keyEquivalent: "Z"))
        editMenu.addItem(NSMenuItem.separator())
        editMenu.addItem(NSMenuItem(title: "Cut", action: #selector(NSText.cut(_:)), keyEquivalent: "x"))
        editMenu.addItem(NSMenuItem(title: "Copy", action: #selector(NSText.copy(_:)), keyEquivalent: "c"))
        editMenu.addItem(NSMenuItem(title: "Paste", action: #selector(NSText.paste(_:)), keyEquivalent: "v"))
        editMenu.addItem(NSMenuItem(title: "Select All", action: #selector(NSText.selectAll(_:)), keyEquivalent: "a"))
        editMenuItem.submenu = editMenu

        NSApp.mainMenu = mainMenu
    }

    private func configureWindow() {
        let initialFrame = NSRect(x: 0, y: 0, width: 1180, height: 780)
        window = NSWindow(
            contentRect: initialFrame,
            styleMask: [.titled, .closable, .miniaturizable, .resizable, .fullSizeContentView],
            backing: .buffered,
            defer: false
        )
        window.title = "Martin Sols"
        window.titleVisibility = .hidden
        window.titlebarAppearsTransparent = true
        window.backgroundColor = Self.martinSolsRed
        window.isMovableByWindowBackground = true
        window.minSize = NSSize(width: 900, height: 640)
        window.isReleasedWhenClosed = false
        rootView = NSView(frame: initialFrame)
        rootView.translatesAutoresizingMaskIntoConstraints = false
        rootView.wantsLayer = true
        rootView.layer?.backgroundColor = Self.splashBackground.cgColor
        window.contentView = rootView
        configureWindowChrome()
        window.center()
        window.makeKeyAndOrderFront(nil)
        NSApp.activate(ignoringOtherApps: true)
        window.orderFrontRegardless()
    }

    private func configureWindowChrome() {
        titleBarView = NSView()
        titleBarView.translatesAutoresizingMaskIntoConstraints = false
        titleBarView.wantsLayer = true
        titleBarView.layer?.backgroundColor = Self.martinSolsRed.cgColor

        let titleLabel = NSTextField(labelWithString: "Martin Sols")
        titleLabel.translatesAutoresizingMaskIntoConstraints = false
        titleLabel.font = NSFont.systemFont(ofSize: 14, weight: .semibold)
        titleLabel.textColor = .white

        settingsButton = NSButton()
        settingsButton.translatesAutoresizingMaskIntoConstraints = false
        settingsButton.bezelStyle = .regularSquare
        settingsButton.image = NSImage(systemSymbolName: "gearshape", accessibilityDescription: "Paramètres de l’app")
        settingsButton.imagePosition = .imageOnly
        settingsButton.isBordered = false
        settingsButton.contentTintColor = .white
        settingsButton.toolTip = "Paramètres de l’app"
        settingsButton.target = self
        settingsButton.action = #selector(openAppSettings)
        settingsButton.wantsLayer = true
        settingsButton.layer?.backgroundColor = NSColor.white.withAlphaComponent(0.14).cgColor
        settingsButton.layer?.cornerRadius = 9

        contentView = NSView()
        contentView.translatesAutoresizingMaskIntoConstraints = false
        contentView.wantsLayer = true
        contentView.layer?.backgroundColor = Self.splashBackground.cgColor

        rootView.addSubview(titleBarView)
        titleBarView.addSubview(titleLabel)
        titleBarView.addSubview(settingsButton)
        rootView.addSubview(contentView)

        NSLayoutConstraint.activate([
            titleBarView.leadingAnchor.constraint(equalTo: rootView.leadingAnchor),
            titleBarView.trailingAnchor.constraint(equalTo: rootView.trailingAnchor),
            titleBarView.topAnchor.constraint(equalTo: rootView.topAnchor),
            titleBarView.heightAnchor.constraint(equalToConstant: Self.titleBarHeight),

            titleLabel.leadingAnchor.constraint(equalTo: titleBarView.leadingAnchor, constant: 104),
            titleLabel.trailingAnchor.constraint(lessThanOrEqualTo: settingsButton.leadingAnchor, constant: -12),
            titleLabel.centerYAnchor.constraint(equalTo: titleBarView.centerYAnchor, constant: 2),

            settingsButton.trailingAnchor.constraint(equalTo: titleBarView.trailingAnchor, constant: -16),
            settingsButton.centerYAnchor.constraint(equalTo: titleBarView.centerYAnchor, constant: 2),
            settingsButton.widthAnchor.constraint(equalToConstant: 32),
            settingsButton.heightAnchor.constraint(equalToConstant: 32),

            contentView.leadingAnchor.constraint(equalTo: rootView.leadingAnchor),
            contentView.trailingAnchor.constraint(equalTo: rootView.trailingAnchor),
            contentView.topAnchor.constraint(equalTo: titleBarView.bottomAnchor),
            contentView.bottomAnchor.constraint(equalTo: rootView.bottomAnchor),
        ])
    }

    private func configureWebView() {
        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = .default()
        configuration.preferences.javaScriptCanOpenWindowsAutomatically = true

        let userContentController = WKUserContentController()
        userContentController.add(self, name: Self.nativeMessageHandlerName)
        userContentController.addUserScript(
            WKUserScript(
                source: nativeBridgeScript(),
                injectionTime: .atDocumentStart,
                forMainFrameOnly: true
            )
        )
        configuration.userContentController = userContentController

        if #available(macOS 11.0, *) {
            configuration.defaultWebpagePreferences.allowsContentJavaScript = true
        }

        webView = WKWebView(frame: .zero, configuration: configuration)
        webView.navigationDelegate = self
        webView.uiDelegate = self
        webView.allowsBackForwardNavigationGestures = true
        webView.setValue(false, forKey: "drawsBackground")
        webView.translatesAutoresizingMaskIntoConstraints = false

        contentView.addSubview(webView)

        NSLayoutConstraint.activate([
            webView.leadingAnchor.constraint(equalTo: contentView.leadingAnchor),
            webView.trailingAnchor.constraint(equalTo: contentView.trailingAnchor),
            webView.topAnchor.constraint(equalTo: contentView.topAnchor),
            webView.bottomAnchor.constraint(equalTo: contentView.bottomAnchor),
        ])
    }

    private func showSplash() {
        let container = NSView(frame: contentView.bounds)
        container.autoresizingMask = [.width, .height]
        container.wantsLayer = true
        container.layer?.backgroundColor = Self.splashBackground.cgColor

        let splashWebView = WKWebView(frame: .zero, configuration: WKWebViewConfiguration())
        splashWebView.translatesAutoresizingMaskIntoConstraints = false

        container.addSubview(splashWebView)
        contentView.addSubview(container)
        splashView = container
        self.splashWebView = splashWebView

        NSLayoutConstraint.activate([
            splashWebView.leadingAnchor.constraint(equalTo: container.leadingAnchor),
            splashWebView.trailingAnchor.constraint(equalTo: container.trailingAnchor),
            splashWebView.topAnchor.constraint(equalTo: container.topAnchor),
            splashWebView.bottomAnchor.constraint(equalTo: container.bottomAnchor),
        ])

        splashWebView.loadHTMLString(splashHTML(), baseURL: Bundle.main.resourceURL)
    }

    private func hideSplash() {
        guard let splashView else {
            return
        }

        self.splashView = nil
        splashWebView = nil

        NSAnimationContext.runAnimationGroup { context in
            context.duration = 0.32
            splashView.animator().alphaValue = 0
        } completionHandler: {
            splashView.removeFromSuperview()
            self.scheduleUpdateCheck()
        }
    }

    @objc private func openAppSettings() {
        let script = """
        (() => {
          document.body?.classList.add('crm-mobile-app', 'crm-mac-app');
          const config = window.MartinSolsCrmConfig;
          if (config) {
            config.mobile = { ...(config.mobile || {}), app: true };
          }
          if (window.MartinSolsMobileApp && typeof window.MartinSolsMobileApp.openSettings === 'function') {
            window.MartinSolsMobileApp.openSettings();
            return true;
          }
          return false;
        })();
        """

        webView.evaluateJavaScript(script) { [weak self] result, _ in
            if (result as? Bool) != true {
                self?.showNativeAppSettingsDialog()
            }
        }
    }

    private func showNativeAppSettingsDialog() {
        let alert = NSAlert()
        alert.messageText = "Paramètres de l’app"
        alert.informativeText = "Version \(appVersionLabel)\n\nMises à jour et futurs réglages de Martin Sols."
        alert.addButton(withTitle: "Rechercher une mise à jour")
        alert.addButton(withTitle: "Fermer")

        if alert.runModal() == .alertFirstButtonReturn {
            checkForAppUpdate(notifyWhenCurrent: true)
        }
    }

    private func scheduleUpdateCheck() {
        if updateCheckStarted {
            return
        }

        updateCheckStarted = true

        DispatchQueue.main.asyncAfter(deadline: .now() + Self.updateCheckDelay) { [weak self] in
            self?.checkForAppUpdate(notifyWhenCurrent: false)
        }
    }

    private func checkForAppUpdate(notifyWhenCurrent: Bool) {
        fetchAppUpdate { [weak self] update, errorMessage in
            DispatchQueue.main.async {
                guard let self else {
                    return
                }

                if let errorMessage {
                    if notifyWhenCurrent {
                        self.showUpdateFailure(message: errorMessage)
                    }

                    return
                }

                guard let update, self.isUpdateNewer(update) else {
                    if notifyWhenCurrent {
                        self.showNoUpdateDialog()
                    }

                    return
                }

                self.showUpdateDialog(update)
            }
        }
    }

    private func fetchAppUpdate(completion: @escaping (MacAppUpdate?, String?) -> Void) {
        var components = URLComponents(url: Self.updateManifestUrl, resolvingAgainstBaseURL: false)
        components?.queryItems = [URLQueryItem(name: "t", value: String(Int(Date().timeIntervalSince1970)))]

        guard let url = components?.url else {
            completion(nil, "Adresse de mise à jour invalide.")

            return
        }

        var request = URLRequest(url: url)
        request.timeoutInterval = 8
        request.setValue("application/json", forHTTPHeaderField: "Accept")

        URLSession.shared.dataTask(with: request) { data, response, error in
            if error != nil {
                completion(nil, "Impossible de vérifier les mises à jour pour le moment.")

                return
            }

            guard
                let httpResponse = response as? HTTPURLResponse,
                (200..<300).contains(httpResponse.statusCode),
                let data,
                let payload = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                let update = Self.macUpdate(from: payload)
            else {
                completion(nil, nil)

                return
            }

            completion(update, nil)
        }.resume()
    }

    private func isUpdateNewer(_ update: MacAppUpdate) -> Bool {
        if update.buildNumber > appBuildNumber {
            return true
        }

        if update.buildNumber == 0 && !update.version.isEmpty {
            return update.version.compare(appVersionName, options: .numeric) == .orderedDescending
        }

        return false
    }

    private func showNoUpdateDialog() {
        let alert = NSAlert()
        alert.messageText = "Application à jour"
        alert.informativeText = "Aucune nouvelle version Mac de Martin Sols n’est disponible pour le moment."
        alert.addButton(withTitle: "OK")
        alert.runModal()
    }

    private func showUpdateFailure(message: String) {
        let alert = NSAlert()
        alert.alertStyle = .warning
        alert.messageText = "Mise à jour indisponible"
        alert.informativeText = message
        alert.addButton(withTitle: "OK")
        alert.runModal()
    }

    private func showUpdateDialog(_ update: MacAppUpdate) {
        let versionLabel = update.version.isEmpty ? String(update.buildNumber) : update.version
        let alert = NSAlert()
        alert.messageText = "Mise à jour disponible"
        alert.informativeText = [
            "Une nouvelle version de Martin Sols est disponible : \(versionLabel).",
            update.releaseNotes,
        ]
        .filter { !$0.isEmpty }
        .joined(separator: "\n\n")
        alert.addButton(withTitle: update.installUrl == nil ? "OK" : "Ouvrir la mise à jour")
        alert.addButton(withTitle: "Plus tard")

        if alert.runModal() == .alertFirstButtonReturn, let installUrl = update.installUrl {
            NSWorkspace.shared.open(installUrl)
        }
    }

    private func isTrustedCrmPage() -> Bool {
        return webView?.url?.host?.caseInsensitiveCompare("crm.jp2.fr") == .orderedSame
    }

    private static var splashBackground: NSColor {
        return NSColor(red: 1, green: 250.0 / 255.0, blue: 247.0 / 255.0, alpha: 1)
    }

    private static var martinSolsRed: NSColor {
        return NSColor(red: 149.0 / 255.0, green: 0, blue: 46.0 / 255.0, alpha: 1)
    }

    private var appVersionName: String {
        return Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "1.37"
    }

    private var appVersionCode: String {
        return Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? "39"
    }

    private var appBuildNumber: Int {
        return Int(appVersionCode) ?? 0
    }

    private var appVersionLabel: String {
        return "\(appVersionName) (\(appVersionCode))"
    }

    private func nativeBridgeScript() -> String {
        let versionName = Self.javaScriptStringLiteral(appVersionName)
        let versionCode = Self.javaScriptStringLiteral(appVersionCode)

        return """
        (() => {
          const versionName = \(versionName);
          const versionCode = \(versionCode);
          const markAsInstalledApp = () => {
            document.documentElement.classList.add('crm-mac-app');
            document.body?.classList.add('crm-mobile-app', 'crm-mac-app');
            const config = window.MartinSolsCrmConfig;
            if (config) {
              config.mobile = { ...(config.mobile || {}), app: true };
            }
          };
          let crmConfig = window.MartinSolsCrmConfig;

          try {
            Object.defineProperty(window, 'MartinSolsCrmConfig', {
              configurable: true,
              get() {
                return crmConfig;
              },
              set(value) {
                crmConfig = value;
                markAsInstalledApp();
              },
            });
          } catch (_) {}

          window.MartinSolsNativeApp = {
            getVersionName() {
              return versionName;
            },
            getVersionCode() {
              return versionCode;
            },
            checkForUpdates() {
              window.webkit?.messageHandlers?.\(Self.nativeMessageHandlerName)?.postMessage({ action: 'checkForUpdates' });
            },
          };

          markAsInstalledApp();
          document.addEventListener('DOMContentLoaded', markAsInstalledApp);
        })();
        """
    }

    private static func javaScriptStringLiteral(_ value: String) -> String {
        guard
            let data = try? JSONEncoder().encode(value),
            let string = String(data: data, encoding: .utf8)
        else {
            return "\"\""
        }

        return string
    }

    private static func macUpdate(from payload: [String: Any]) -> MacAppUpdate? {
        guard
            let macPayload = payload["macos"] as? [String: Any] ?? payload["mac"] as? [String: Any]
        else {
            return nil
        }

        let version = stringValue(macPayload, keys: ["versionName", "version"])
        let buildNumber = intValue(macPayload, keys: ["buildNumber", "versionCode", "build"])
        let installUrlString = stringValue(macPayload, keys: ["pkgUrl", "installUrl", "url"])
        let installUrl = installUrlString.isEmpty ? nil : URL(string: installUrlString)
        let releaseNotes = stringValue(macPayload, keys: ["releaseNotes", "notes"])

        if version.isEmpty && buildNumber == 0 && installUrl == nil {
            return nil
        }

        return MacAppUpdate(
            version: version,
            buildNumber: buildNumber,
            installUrl: installUrl,
            releaseNotes: releaseNotes
        )
    }

    private static func stringValue(_ payload: [String: Any], keys: [String]) -> String {
        for key in keys {
            if let value = payload[key] as? String {
                return value.trimmingCharacters(in: .whitespacesAndNewlines)
            }

            if let value = payload[key] as? NSNumber {
                return value.stringValue
            }
        }

        return ""
    }

    private static func intValue(_ payload: [String: Any], keys: [String]) -> Int {
        for key in keys {
            if let value = payload[key] as? Int {
                return value
            }

            if let value = payload[key] as? NSNumber {
                return value.intValue
            }

            if let value = payload[key] as? String, let intValue = Int(value) {
                return intValue
            }
        }

        return 0
    }

    private func splashHTML() -> String {
        return """
        <!doctype html>
        <html lang="fr">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <style>
            html,
            body {
              width: 100%;
              height: 100%;
              margin: 0;
              overflow: hidden;
              background: #fffaf7;
            }

            body {
              display: grid;
              place-items: center;
            }

            img {
              display: block;
              width: min(26vw, 340px);
              max-width: 340px;
              max-height: 70vh;
              object-fit: contain;
            }
          </style>
        </head>
        <body>
          <img src="opening-animation.gif" alt="">
        </body>
        </html>
        """
    }

    private struct MacAppUpdate {
        let version: String
        let buildNumber: Int
        let installUrl: URL?
        let releaseNotes: String
    }
}

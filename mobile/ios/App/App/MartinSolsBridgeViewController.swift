import Capacitor
import UIKit
import WebKit

@objc(MartinSolsBridgeViewController)
class MartinSolsBridgeViewController: CAPBridgeViewController, WKScriptMessageHandler {
    private static let updateManifestUrl = URL(string: "https://raw.githubusercontent.com/jp2creation/hub/main/mobile/releases/martin-sols-update.json")!
    private static let nativeMessageHandlerName = "martinSolsNativeApp"
    private static let updateCheckDelay: TimeInterval = 7

    private weak var crmWebView: WKWebView?
    private var updateCheckStarted = false

    override var preferredStatusBarStyle: UIStatusBarStyle {
        return .lightContent
    }

    deinit {
        crmWebView?.configuration.userContentController.removeScriptMessageHandler(forName: Self.nativeMessageHandlerName)
    }

    override func viewDidAppear(_ animated: Bool) {
        super.viewDidAppear(animated)
        scheduleUpdateCheck()
    }

    override func webViewConfiguration(for instanceConfiguration: InstanceConfiguration) -> WKWebViewConfiguration {
        let configuration = super.webViewConfiguration(for: instanceConfiguration)
        configuration.preferences.javaScriptCanOpenWindowsAutomatically = true
        configuration.allowsInlineMediaPlayback = true
        configuration.mediaTypesRequiringUserActionForPlayback = []
        configuration.userContentController.add(self, name: Self.nativeMessageHandlerName)
        configuration.userContentController.addUserScript(
            WKUserScript(
                source: nativeBridgeScript(),
                injectionTime: .atDocumentStart,
                forMainFrameOnly: true
            )
        )

        if #available(iOS 14.0, *) {
            configuration.defaultWebpagePreferences.allowsContentJavaScript = true
        }

        return configuration
    }

    override func webView(with frame: CGRect, configuration: WKWebViewConfiguration) -> WKWebView {
        let crmWebView = super.webView(with: frame, configuration: configuration)
        self.crmWebView = crmWebView
        crmWebView.backgroundColor = UIColor(red: 245.0 / 255.0, green: 247.0 / 255.0, blue: 251.0 / 255.0, alpha: 1)
        crmWebView.allowsBackForwardNavigationGestures = true
        crmWebView.scrollView.keyboardDismissMode = .interactive
        crmWebView.scrollView.contentInsetAdjustmentBehavior = .automatic
        return crmWebView
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
        } else if action == "openDeviceSecuritySettings" {
            openDeviceSecuritySettings()
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
        if !notifyWhenCurrent && !isTrustedCrmPage() {
            return
        }

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

    private func fetchAppUpdate(completion: @escaping (IosAppUpdate?, String?) -> Void) {
        var components = URLComponents(url: Self.updateManifestUrl, resolvingAgainstBaseURL: false)
        components?.queryItems = [URLQueryItem(name: "t", value: String(Int(Date().timeIntervalSince1970)))]

        guard let url = components?.url else {
            completion(nil, "Adresse de mise a jour invalide.")

            return
        }

        var request = URLRequest(url: url)
        request.timeoutInterval = 8
        request.setValue("application/json", forHTTPHeaderField: "Accept")

        URLSession.shared.dataTask(with: request) { data, response, error in
            if error != nil {
                completion(nil, "Impossible de verifier les mises a jour pour le moment.")

                return
            }

            guard
                let httpResponse = response as? HTTPURLResponse,
                (200..<300).contains(httpResponse.statusCode),
                let data,
                let payload = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                let update = Self.iosUpdate(from: payload)
            else {
                completion(nil, nil)

                return
            }

            completion(update, nil)
        }.resume()
    }

    private func isUpdateNewer(_ update: IosAppUpdate) -> Bool {
        if update.buildNumber > appBuildNumber {
            return true
        }

        if update.buildNumber == 0 && !update.version.isEmpty {
            return update.version.compare(appVersionName, options: .numeric) == .orderedDescending
        }

        return false
    }

    private func showNoUpdateDialog() {
        let alert = UIAlertController(
            title: "Application a jour",
            message: "Aucune nouvelle version iPhone/iPad de Martin Sols n'est disponible pour le moment.",
            preferredStyle: .alert
        )
        alert.addAction(UIAlertAction(title: "OK", style: .default))
        presentAlert(alert)
    }

    private func showUpdateFailure(message: String) {
        let alert = UIAlertController(
            title: "Mise a jour indisponible",
            message: message,
            preferredStyle: .alert
        )
        alert.addAction(UIAlertAction(title: "OK", style: .default))
        presentAlert(alert)
    }

    private func showUpdateDialog(_ update: IosAppUpdate) {
        let versionLabel = update.version.isEmpty ? String(update.buildNumber) : update.version
        let notes = [
            "Une nouvelle version iPhone/iPad de Martin Sols est disponible : \(versionLabel).",
            update.releaseNotes,
            update.distribution.isEmpty ? "" : "Distribution : \(update.distribution).",
            update.installUrl == nil ? "L'installation iOS doit passer par App Store, TestFlight, MDM ou distribution entreprise." : "",
        ]
        .filter { !$0.isEmpty }
        .joined(separator: "\n\n")

        let alert = UIAlertController(
            title: "Mise a jour disponible",
            message: notes,
            preferredStyle: .alert
        )

        if let installUrl = update.installUrl {
            alert.addAction(
                UIAlertAction(title: "Ouvrir la mise a jour", style: .default) { _ in
                    UIApplication.shared.open(installUrl)
                }
            )
            alert.addAction(UIAlertAction(title: "Plus tard", style: .cancel))
        } else {
            alert.addAction(UIAlertAction(title: "OK", style: .default))
        }

        presentAlert(alert)
    }

    private func presentAlert(_ alert: UIAlertController) {
        var presenter: UIViewController? = self

        while let presentedViewController = presenter?.presentedViewController {
            presenter = presentedViewController
        }

        presenter?.present(alert, animated: true)
    }

    private func openDeviceSecuritySettings() {
        guard let settingsUrl = URL(string: UIApplication.openSettingsURLString) else {
            return
        }

        UIApplication.shared.open(settingsUrl)
    }

    private func isTrustedCrmPage() -> Bool {
        return crmWebView?.url?.host?.caseInsensitiveCompare("crm.jp2.fr") == .orderedSame
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

    private func nativeBridgeScript() -> String {
        let versionName = Self.javaScriptStringLiteral(appVersionName)
        let versionCode = Self.javaScriptStringLiteral(appVersionCode)

        return """
        (() => {
          const versionName = \(versionName);
          const versionCode = \(versionCode);
          const postNativeMessage = (action) => {
            const handler = window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.\(Self.nativeMessageHandlerName);

            if (handler) {
              handler.postMessage({ action });
            }
          };
          const markAsInstalledApp = () => {
            document.documentElement.classList.add('crm-ios-app');
            document.body?.classList.add('crm-mobile-app', 'crm-ios-app');
            const config = window.MartinSolsCrmConfig;

            if (config) {
              config.mobile = Object.assign({}, config.mobile || {}, { app: true });
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
              postNativeMessage('checkForUpdates');
            },
            getMobileAuthStatus() {
              return JSON.stringify({
                ok: true,
                available: false,
                configured: false,
                deviceSecure: false,
                appCodeConfigured: false,
                hasSession: false,
                label: 'iPhone/iPad',
                message: 'Securite locale prevue pour iPhone et iPad.',
              });
            },
            openDeviceSecuritySettings() {
              postNativeMessage('openDeviceSecuritySettings');
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

    private static func iosUpdate(from payload: [String: Any]) -> IosAppUpdate? {
        guard
            let iosPayload = payload["ios"] as? [String: Any]
        else {
            return nil
        }

        let version = stringValue(iosPayload, keys: ["versionName", "version"])
        let buildNumber = intValue(iosPayload, keys: ["buildNumber", "versionCode", "build"])
        let installUrlString = stringValue(iosPayload, keys: ["installUrl", "appStoreUrl", "testFlightUrl", "url"])
        let installUrl = installUrlString.isEmpty ? nil : URL(string: installUrlString)
        let distribution = stringValue(iosPayload, keys: ["distribution"])
        let releaseNotes = stringValue(iosPayload, keys: ["releaseNotes", "notes"])

        if version.isEmpty && buildNumber == 0 && installUrl == nil {
            return nil
        }

        return IosAppUpdate(
            version: version,
            buildNumber: buildNumber,
            installUrl: installUrl,
            distribution: distribution,
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

    private struct IosAppUpdate {
        let version: String
        let buildNumber: Int
        let installUrl: URL?
        let distribution: String
        let releaseNotes: String
    }
}

# ios-app — TP-HR iOS wrapper (Capacitor)

WKWebView shell around `https://hr.tp-asset.com`, built for **Ad Hoc**
distribution: employees install it from a download link, not the App Store.

The web app is the product. This wrapper exists only for what a PWA cannot do
on iOS — Face ID, APNs, background location, native file downloads.

> Not shipped to the web server. `ios-app/` is denied in `../.htaccess` and
> `../deploy/nginx-deny-internal-paths.conf`. It lives inside the tp-hr repo
> because `htdocs/` is not writable on the dev machine; splitting it into its
> own repo later is a clean move — nothing here imports from the PHP app.

## Prerequisites

Xcode alone is not enough. **The iOS platform components must be downloaded**,
which Xcode 26 does not do on install:

```bash
xcodebuild -downloadPlatform iOS
```

That is a multi-GB download and asks for an admin password. Until it finishes,
`xcodebuild` fails with *"iOS 26.5 is not installed"* and no simulator can boot
(`xcrun simctl list runtimes` shows nothing).

CocoaPods is **not** needed — Capacitor 8 uses Swift Package Manager.

## Build and run

```bash
npm install
npx cap sync ios
npx cap open ios
```

Then pick a simulator or device in Xcode and press Run. From the command line:

```bash
xcodebuild -project ios/App/App.xcodeproj -scheme App -sdk iphonesimulator -configuration Debug -destination 'platform=iOS Simulator,name=iPhone 17' build
```

## Configuration

`capacitor.config.json` sets `server.url` to production, so the app always
loads the live site — there is no bundled web build to keep in sync, and a
web deploy updates the app instantly with no resubmission.

`server.allowNavigation` lists the hosts allowed to stay inside the webview.
`access.line.me` is there because LINE login would otherwise bounce out to
Safari and lose the session on the way back.

To point a build at a staging server, edit `server.url` and rebuild.

## Ad Hoc distribution (≤100 devices/year)

Requires an Apple Developer Program membership ($99/yr).

1. Collect each employee's device UDID and register it at
   developer.apple.com → Devices.
2. Create an Ad Hoc provisioning profile covering `com.tpasset.tphr` and
   those devices.
3. Archive and export:

```bash
xcodebuild -project ios/App/App.xcodeproj -scheme App -configuration Release -archivePath build/App.xcarchive archive
xcodebuild -exportArchive -archivePath build/App.xcarchive -exportPath build/ipa -exportOptionsPlist ExportOptions.plist
```

`ExportOptions.plist` (not committed — it names your team):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>method</key><string>ad-hoc</string>
  <key>teamID</key><string>YOUR_TEAM_ID</string>
  <key>compileBitcode</key><false/>
</dict>
</plist>
```

4. Host `App.ipa` plus a `manifest.plist` on HTTPS with a trusted certificate,
   and link to it:

```
itms-services://?action=download-manifest&url=https://hr.tp-asset.com/ios/manifest.plist
```

The link must be opened in **Safari** — Chrome on iOS ignores `itms-services`.

### The annual chore

Ad Hoc provisioning profiles expire after one year. When one lapses the app
stops launching — it does not warn first. Re-sign and redistribute before the
expiry date, and note it somewhere people will see it.

Adding a device mid-year also means regenerating the profile and re-exporting.

## What this wrapper does not do yet

Nothing native is wired up. The shell renders the site; that is all. Each of
these is a separate piece of work:

| Capability | Plugin | Also needs |
|---|---|---|
| Face ID / Touch ID lock | `@capacitor-community/biometric-auth` | a lock screen in the web app |
| APNs push | `@capacitor/push-notifications` | APNs key, device-token table, server sender |
| Native camera for check-in selfies | `@capacitor/camera` | swap the web capture path |
| Background GPS | `@capacitor/geolocation` + background mode | `NSLocationAlwaysUsageDescription`, App Review N/A for Ad Hoc |
| Offline check-in queue | `@capacitor/preferences` or SQLite | conflict handling on sync |
| PDF payslip downloads | `@capacitor/filesystem` | WKWebView does not download on its own |

Until push is wired natively, the PWA's Web Push (see `../core/Services/PushService.php`)
covers notifications on iOS 16.4+.

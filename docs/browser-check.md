# Browser check

Catches bots, moderators' virtual machines, emulators and anti-detect browsers by **contradictions the browser reports about itself**. Examples: a software GPU instead of a real graphics card, "iPhone" in the User-Agent while the platform is Linux, a "phone" without a touch screen. Real phones, including the Facebook, Instagram and TikTok in-app browsers, show no such contradictions, so the check can act on them without cutting real visitors.

It is configured in one place for the whole tracker: **⚙ → Settings → Bots → Browser check**.

## What it is not

It is **not** a visitor ID and it does not count unique visitors. Fingerprints of real phones collide: every iPhone of the same model and iOS version looks practically identical, and in-app browsers even more so. Using a fingerprint for uniqueness would merge different people into one and undercount uniques. Orbitra never does that: uniques are counted exactly as before, by IP / IP+User-Agent. A short fingerprint is stored only as a diagnostic in the click card.

## Modes

| Mode | What it does | Risk of losing real visitors |
|---|---|---|
| **Off** | Nothing is collected; everything works as before | — |
| **Observe only** | Blocks nothing. The click card shows what looked suspicious and which browser it was | None |
| **Soft** (recommended) | Acts only on hard evidence (table below) | Minimal: real devices don't show these signs |
| **Strict** | Also acts on indirect signs | Yes: a real person on a VPN, roaming or with unusual settings can be marked as a bot |

**Default:** a fresh install starts in **Soft**. An existing install that updates to this version starts **Off** and the panel shows a one-time banner: *Turn on soft mode* / *Learn more* / *Not now*. An update never changes your routing by itself.

## What "acts" means

- **In a cloak stream with the JS check on** (stream → cloak settings → *JavaScript check*), the signals are collected during that same JS step and judged **before** the money page is served. A browser the mode acts on gets the safe page right away. The cloak reason shows which sign it was, for example `bc_software_gpu`.
- **Everywhere else** (landings without the JS check, external pages with `tracking.js` / `kclient.js` / `kclient.php`), the signals are sent from the landing after it loads. The click is marked as a bot (`is_bot`), which cleans up reports and feeds the "bot" stream filter. The visit itself isn't stopped: there is no intermediate step to stop it in, and adding one would slow down every real visitor.

So if you want the check to send suspicious browsers to the safe page, turn on the JS check in the cloak stream. Soft mode then protects that stream at no extra cost: the JS step already exists, and the check just runs inside it.

A single cloak stream can opt out: **cloak settings → Browser check → Off for this stream**.

## The signs

**Hard evidence** (Soft and Strict act on it):

| Sign | Why a real device never shows it |
|---|---|
| **Browser automation** | `navigator.webdriver` is true, or the browser is HeadlessChrome |
| **Virtual machine without a GPU** | The WebGL renderer is software: SwiftShader, llvmpipe, Microsoft Basic Render Driver, VirtualBox, VMware. Phones always have a hardware GPU |
| **Phone User-Agent on a desktop** | "iPhone" in the UA but the platform isn't iPhone; "Android" in the UA on a Windows/Mac platform. iOS always reports `iPhone` |
| **Phone without a touch screen** | A phone UA with `maxTouchPoints = 0` and no touch events |
| **Client Hints: "not mobile"** | An Android phone UA while `userAgentData.mobile` is false |

**Indirect signs** (only Strict acts on them):

| Sign | When a real person shows it too |
|---|---|
| **Timezone of another country** | VPN users, travellers, roaming. Neighbouring zones with the same offset don't count |
| **Browser language ≠ header** | Rare setups, some privacy extensions |
| **Android on x86** | Emulators (BlueStacks, AVD) and a few Chromebooks |
| **Platform spoof on desktop** | A UA-switcher extension |
| **Zero-size window** | Old headless builds. Desktop only: Android in-app browsers legitimately report 0 |

A missing signal never counts against the visitor. If WebGL is off (iPhone Lockdown mode, privacy settings), the GPU sign simply doesn't apply.

## Where to see the results

- **Click card** → *Browser check*: the verdict (Clean / Suspicious / Bot), each sign with its detail (for example the exact GPU string), the platform, the browser timezone and where it was checked (cloak JS step or landing).
- **Cloak reasons**: `bc_software_gpu`, `bc_ua_platform`, `bc_mobile_no_touch` and so on, with plain-language descriptions in the cloak diagnostics.
- **Settings → Bots → Browser check**: visits checked over the last 7 days, how many were bots and suspicious, and the most frequent signs.

## How to start safely

1. Turn on **Observe only** for a day or two and look at the stats and a few click cards. You see how much of your traffic would be affected, without any risk.
2. Switch to **Soft**. Turn on the JS check in the cloak streams where you want such visitors on the safe page.
3. Use **Strict** only on traffic where VPN users and travellers don't matter to you.

## Technical notes

- Collector: `/bc.js` (≈2 KB). In the cloak JS step it is inlined and its signals ride the next URL as `_ocb`. On landings it is loaded with `defer` and posts once to `POST /pixel.gif?action=bc`, signed with an HMAC of the click.
- Verdicts: table `click_browser_checks` (one row per checked click, cleaned with the click statistics retention). Migration 77. A second report for the same click is ignored.
- Mode: setting `browser_check_mode` (`off` / `observe` / `soft` / `strict`). Stream opt-out: `"browser_check": "off"` in the cloak stream's settings.
- Code: `core/BrowserCheck.php`, `bc.js`. Test: `tests/browser_check_test.php`.

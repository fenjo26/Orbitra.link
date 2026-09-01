# PWA constructor preset assets

Bundled sample images for the PWA landing presets. Drop the files here with
the exact names below and the constructor presets / live preview pick them up
automatically — no rebuild needed (the constructor references these URLs as
plain static paths).

Recommended: icon **512×512 PNG** (square, emblem centered, ~20% safe margin),
screenshots **1080×1920 PNG** (portrait 9:16, one app screen per image).

The migration that introduces them also seeds copies into the Content Gallery
folder **"PWA Presets"**, so they are browsable/reusable with the ordinary
gallery mechanics (copy URL, archive, export). The constructor itself keeps
using these committed `/assets/` URLs — gallery copies are for humans.

## Expected layout

| File | Used by preset |
|---|---|
| `lucky-casino/icon.png` | Lucky Casino |
| `lucky-casino/screen-1.png` … `screen-3.png` | Lucky Casino |
| `lucky-casino/app-hero.png` | Lucky Casino — installed-app screen background (1080×1440) |
| `bet-sport/icon.png` | BetMaster Sport |
| `bet-sport/screen-1.png` … `screen-3.png` | BetMaster Sport |
| `bet-sport/app-hero.png` | BetMaster Sport — installed-app screen background (1080×1440) |
| `neon-slots/icon.png` | Neon Slots 777 |
| `neon-slots/screen-1.png` … `screen-3.png` | Neon Slots 777 |
| `neon-slots/app-hero.png` | Neon Slots 777 — installed-app screen background (1080×1440) |
| `fit-club/icon.png` | FitClub Pro |
| `fit-club/screen-1.png` … `screen-3.png` | FitClub Pro |
| `fit-club/app-hero.png` | FitClub Pro — installed-app screen background (1080×1440) |

Missing files are simply not shown (the page hides broken images), so you can
ship the folder incrementally. Operators can always override any image with
their own via the media picker in the PWA constructor.

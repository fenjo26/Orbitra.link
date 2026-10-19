# Content Gallery

The Gallery is the tracker's shared media library: folders, uploads, search and the
`MediaPicker` through which icons and screenshots are chosen in the PWA,
landing and offer editors. Files live on your own server (`uploads/media/`), with no
external storage.

---

## 1. The Gallery page

- **Folders** — folder tiles above the file grid; a click opens the folder. Deleting
  a folder does not lose files: they move to the root.
- **Upload** — a button or drag-and-drop. The server renames the file
  (hash prefix + suffix); the original name is kept in the metadata.
- **Filters** — status, user, name search.
- **Bulk operations** — move to a folder, delete.
- **Deletion is soft** (`is_active=0`): the file disappears from the picker, but pages
  that already use the image keep showing it.

### Limits

| Setting | Value |
|---|---|
| Formats | webp, jpg, jpeg, png, gif (SVG is banned — XSS) |
| Size | up to 10 MB |
| Validation | `getimagesize()` + MIME `image/*` — a renamed file will not pass |

---

## 2. MediaPicker in editors

The image-picking buttons in **PwaEditor** (512×512 icon, screenshots),
**LandingEditor** and **OfferEditor** open the shared picker: the Gallery
asset grid, an upload tab, and a list refresh on every open.

### sizeContract: size requirements

The picker can be given a size contract, e.g. a PWA icon —
`512×512, crop: true`:

- tiles are labeled: **exact match** / **croppable** / **too small**;
- picking an "oversized" image opens the cropper — a frame with a fixed aspect
  ratio; the result is saved as a **new library asset** of exactly the contract
  size (PNG), the original stays untouched;
- images smaller than the contract cannot be selected.

Without a contract (screenshots) any image is picked as-is.

### What is used where

- **PwaEditor**: the app icon (512×512 with cropping), storefront
  screenshots; the manifest is built from the same source (192/512 + maskable
  from a single origin);
- **LandingEditor / OfferEditor**: landing/offer previews and assets.

## Videos

Manual upload accepts **mp4 and webm up to 100 MB** alongside the image types (10 MB). The check is a signature read (`ftyp` / EBML magic), not the file name — a text file renamed to `.mp4` is rejected, same honesty rule as the creative worker. Videos have no width/height (no ffmpeg involved); in the gallery grid and the media picker they render as a playable `<video>` preview, and image-only slots (an icon crop, a screenshot contract) simply don't match them.


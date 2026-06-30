# Marketing assets

Store / listing artwork for LeadHub. These are **not** shipped in the package
(`/resources/marketing` is export-ignored), they're source files for the
Statamic Marketplace and GitHub listing.

| File | Size | Use |
|------|------|-----|
| `cover.svg` | 1600×800 (vector) | Editable master |
| `cover.png` | 3200×1600 (2×) | Upload-ready store cover / social banner |

## Regenerating `cover.png` from `cover.svg`

The PNG is rendered from the SVG with headless Chromium. The one gotcha:
`--force-device-scale-factor` halves the CSS viewport (window size is in device
pixels), which makes a 1600-wide SVG overflow and the bottom clip off. Render
through CDP with a single scale factor instead — set the device metrics to the
target CSS size at `deviceScaleFactor: 2` and capture the full viewport (no clip
scale on top):

```js
await send('Emulation.setDeviceMetricsOverride',
  { width: 1600, height: 800, deviceScaleFactor: 2, mobile: false });
const shot = await send('Page.captureScreenshot', { format: 'png' }); // → 3200×1600
```

Fonts: the SVG uses a `Liberation Sans / DejaVu Sans / Arial` stack so it renders
identically on Linux CI and macOS.

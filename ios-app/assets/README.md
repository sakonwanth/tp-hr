# assets

`appicon-source.svg` — the app-icon master, derived from
`../../assets/icons/tphr-app-icon.svg` with two deliberate changes:

- **`rx` removed.** iOS applies its own corner mask; a pre-rounded source
  produces visible double corners.
- **Sized 1024×1024** rather than 256, so the rasterizer renders at native
  resolution instead of upscaling a thumbnail.

Regenerate `../ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png`:

```bash
qlmanage -t -s 1024 -o /tmp appicon-source.svg
php -r '$s=imagecreatefrompng("/tmp/appicon-source.svg.png");$o=imagecreatetruecolor(1024,1024);
imagefilledrectangle($o,0,0,1024,1024,imagecolorallocate($o,0x7f,0x4b,0xff));
imagecopyresampled($o,$s,0,0,0,0,1024,1024,imagesx($s),imagesy($s));imagesavealpha($o,false);
imagepng($o,"AppIcon-512@2x.png",9);'
```

The flatten step is not optional: iOS rejects an app icon with an alpha
channel and renders transparent pixels black on device.

const fs = require('node:fs/promises');
const path = require('node:path');
const pngToIcoModule = require('png-to-ico');

const pngToIco = pngToIcoModule.default || pngToIcoModule;

const rootDir = path.resolve(__dirname, '..');
const buildDir = path.join(rootDir, 'build');
const macIconPath = path.resolve(
  rootDir,
  '..',
  'ios',
  'App',
  'MacApp',
  'Assets.xcassets',
  'AppIcon.appiconset',
  'AppIcon-512@2x.png',
);

async function main() {
  await fs.mkdir(buildDir, { recursive: true });
  await fs.copyFile(macIconPath, path.join(buildDir, 'icon.png'));

  const iconBuffer = await pngToIco(macIconPath);
  await fs.writeFile(path.join(buildDir, 'icon.ico'), iconBuffer);
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

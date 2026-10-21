// 复用本机 HBuilderX 的原版 Vite/uni 插件与平台包，不加载 IDE 进程通信或发布入口。
const path = require('node:path')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.argv[2], 'plugins/uniapp-cli-vite/package.json'))
const platform = process.argv[3]
req('@dcloudio/uni-cli-shared/dist/alias')
req('@dcloudio/uni-cli-shared/dist/polyfill')
process.env.VITE_CJS_IGNORE_WARNING = 'true'
req('@dcloudio/vite-plugin-uni/dist/cli/utils').initEnv('build', { platform })
const uni = req('@dcloudio/vite-plugin-uni').default
console.log('SC3 load original platform plugins:', platform)
req('vite').build({
  root: process.cwd(),
  configFile: false,
  plugins: [uni()],
  build: { outDir: process.env.UNI_OUTPUT_DIR, emptyOutDir: true },
}).then(() => {
  console.log('SC3 complete uni-app build:', platform)
  process.exit(0)
}).catch(error => {
  console.error(error)
  process.exit(1)
})

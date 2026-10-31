import test from 'node:test'
import assert from 'node:assert/strict'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import path from 'node:path'
import fs from 'node:fs'

const exec=promisify(execFile)
const defaultWinPhp = fs.existsSync('D:/java/php/php.exe') ? 'D:/java/php/php.exe' : (fs.existsSync('D:/php.exe') ? 'D:/php.exe' : 'php')
const php=process.env.SCENE_PHP || (process.platform==='win32' ? defaultWinPhp : 'php')
const script=path.resolve('my-bagisto-store/qa/scenes/preflight.php')
async function run(args,env){
  try { const result=await exec(php,[script,...args],{env:{...process.env,...env},timeout:10000});return {code:0,body:JSON.parse(result.stdout)} }
  catch(error){if(error.stdout)return {code:error.code,body:JSON.parse(error.stdout)};throw error}
}
test('测试预检在 bootstrap 前拒绝生产环境、业务库和共享测试库',async()=>{
  for(const env of [{APP_ENV:'production',DB_DATABASE:'summon_mecha_scenes_test'}, {APP_ENV:'testing',DB_DATABASE:'root'}, {APP_ENV:'testing',DB_DATABASE:'summon_mecha_step10_test'}, {APP_ENV:'testing',DB_DATABASE:''}]){
    const result=await run([],env);assert.equal(result.code,2);assert.equal(result.body.code,'SCENE_TEST_ENVIRONMENT_REQUIRED')
    assert.equal(result.body.bootstrap_loaded,false);assert.equal(result.body.writes_performed,false)
  }
})
test('环境检查模式不加载业务 bootstrap 或连接数据库，不能冒充全量预检',async()=>{
  const result=await run(['--check-env-only'],{APP_ENV:'testing',DB_DATABASE:'summon_mecha_scenes_test'})
  assert.equal(result.code,0);assert.equal(result.body.stage,'process_environment_only')
  assert.equal(result.body.bootstrap_loaded,false);assert.equal(result.body.database_identity_verified,false)
})
test('未知参数被拒绝，help 不包含连接凭证',async()=>{
  assert.equal((await run(['--migrate'],{})).code,2)
  const result=await run(['--help'],{});assert.equal(result.code,0)
  assert.equal(result.body.mode,'read_only');assert.equal(result.body.database_password_required_in_report,false)
})
test('独立环境路径无效或重复时在 bootstrap 前拒绝',async()=>{
  const env={APP_ENV:'testing',DB_DATABASE:'summon_mecha_scenes_test'}
  for(const args of [
    ['--environment-path='+path.resolve('.claude/sc3-path-that-does-not-exist')],
    ['--environment-path='+process.cwd(),'--environment-path='+process.cwd()],
  ]){
    const result=await run(args,env)
    assert.equal(result.code,2);assert.equal(result.body.code,'SCENE_ENVIRONMENT_PATH_INVALID')
    assert.equal(result.body.bootstrap_loaded,false)
  }
})
test('自选环境路径不能绕过 testing 和专用测试库守卫',async()=>{
  for(const env of [{APP_ENV:'production',DB_DATABASE:'summon_mecha_scenes_test'},{APP_ENV:'testing',DB_DATABASE:'root'}]){
    const result=await run(['--environment-path='+process.cwd(),'--check-env-only'],env)
    assert.equal(result.code,2);assert.equal(result.body.code,'SCENE_TEST_ENVIRONMENT_REQUIRED')
  }
})

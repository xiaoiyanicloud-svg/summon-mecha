<?php
require __DIR__.'/preflight.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\Request;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillExecution;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\Deploy\DeviceCredentialService;
use Webkul\Shop\Services\Deploy\SigningService;
use Webkul\Shop\Services\SkillPackage\PackageFingerprint;
$mode=$argv[1]??'setup';$metaFile=__DIR__.'/fixture-ids.json';
if ($mode==='setup') {
 $oldLive=DeviceBinding::where('did','like','live_http_%')->pluck('id')->all();
 $did='ind478_'.uniqid();
 $b=DeviceBinding::create(['did'=>$did,'sn'=>'SN_'.$did,'source'=>'agent','bind_status'=>'bound','bound_at'=>now()]);
 $cred=app(DeviceCredentialService::class)->issue($b);
 $pid=DB::table('products')->insertGetId(['sku'=>$did,'type'=>'simple','created_at'=>now(),'updated_at'=>now()]);
 $v=SkillVersion::create(['product_id'=>$pid,'version'=>'1.0.0','channel'=>'stable','status'=>SkillVersion::STATUS_PUBLISHED,'license_mode'=>'per_device']);
 $path='qa-independent-478/'.$did.'.skill';$disk=Storage::disk(SkillAsset::disk());$absolute=$disk->path($path);@mkdir(dirname($absolute),0777,true);
 $z=new ZipArchive();$z->open($absolute,ZipArchive::CREATE|ZipArchive::OVERWRITE);$z->addFromString('manifest.json',json_encode(['entrypoint'=>'action.py']));$z->addFromString('action.py','def run(context=None): return {"status":"SUCCEEDED"}');$z->close();
 $asset=SkillAsset::create(['skill_version_id'=>$v->id,'carrier_type'=>'file','file_path'=>$path,'file_name'=>basename($path),'file_hash'=>hash_file('sha256',$absolute),'file_size'=>filesize($absolute),'entrypoint'=>'action.py','sort_order'=>0]);
 $task=DeployTask::create(['task_no'=>'IND_'.uniqid(),'did'=>$did,'skill_id'=>$pid,'skill_version_id'=>$v->id,'package_asset_id'=>$asset->id,'package_hash'=>app(PackageFingerprint::class)->forVersion($v->id),'operation'=>1,'protocol_version'=>DeployTask::PROTOCOL_V2,'command_seq'=>1,'status'=>'dispatching','payload'=>[]]);
 URL::forceRootUrl(getenv('AUDIT_BASE_URL'));URL::forceScheme('http');
 $command=app(DeployTaskService::class)->buildCommand($task);
 file_put_contents($metaFile,json_encode(['did'=>$did,'binding'=>$b->id,'product'=>$pid,'version'=>$v->id,'asset'=>$asset->id,'path'=>$path,'task'=>$task->id,'old_live_ids'=>$oldLive]));
 echo json_encode(['did'=>$did,'secret'=>$cred['secret'],'public_key'=>app(SigningService::class)->publicKeyPem(),'command'=>$command]),PHP_EOL;
} elseif ($mode==='cleanup') {
 $m=json_decode(file_get_contents($metaFile),true);
 Storage::disk(SkillAsset::disk())->delete($m['path']);
 DeployTask::whereKey($m['task'])->delete();SkillAsset::whereKey($m['asset'])->delete();SkillVersion::whereKey($m['version'])->delete();DB::table('products')->where('id',$m['product'])->delete();DeviceBinding::whereKey($m['binding'])->delete();
 $newLive=DeviceBinding::where('did','like','live_http_%')->whereNotIn('id',$m['old_live_ids'])->get();
 foreach($newLive as $binding) {DeployTask::where('did',$binding->did)->delete();$binding->delete();}
 $keysDir=config('deploy_gateways.keys.dir');if(!str_starts_with($keysDir,'audit-478a036-'))throw new RuntimeException('Refusing unrelated key cleanup');
 foreach(['platform_private.pem','platform_public.pem','device_ca_private.pem','device_ca_public.pem'] as $leaf)Storage::disk(config('deploy_gateways.keys.disk'))->delete($keysDir.'/'.$leaf);
 echo json_encode(['test_fixtures_cleaned'=>true,'live_fixtures_cleaned'=>count($newLive)]),PHP_EOL;
} elseif ($mode==='customer') {
 DB::beginTransaction();
 try {
  $cust=Webkul\Customer\Models\Customer::create(['first_name'=>'audit','last_name'=>'478','email'=>'audit478_'.uniqid().'@example.com','password'=>bcrypt('audit-only'),'channel_id'=>core()->getDefaultChannel()->id,'status'=>1,'is_verified'=>1]);
  auth()->guard('customer')->setUser($cust);
  $did='customer478_'.uniqid();$binding=DeviceBinding::create(['did'=>$did,'sn'=>'SN_'.$did,'source'=>'agent','bind_status'=>'bound','owner_customer_id'=>$cust->id]);
  $device=Device::create(['customer_id'=>$cust->id,'binding_id'=>$binding->id,'name'=>'audit-only','sn'=>$binding->sn,'model_code'=>'unitree_g1','bind_status'=>Device::STATUS_BOUND,'bound_at'=>now()]);
  $pid=DB::table('products')->insertGetId(['sku'=>$did,'type'=>'simple','created_at'=>now(),'updated_at'=>now()]);
  $v=SkillVersion::create(['product_id'=>$pid,'version'=>'1.0.0','channel'=>'stable','status'=>SkillVersion::STATUS_PUBLISHED,'license_mode'=>'per_device']);
  $e=SkillEntitlement::create(['customer_id'=>$cust->id,'device_id'=>$device->id,'product_id'=>$pid,'skill_version_id'=>$v->id,'delivery_channel'=>'agent','status'=>SkillEntitlement::STATUS_ACTIVE,'expires_at'=>now()->subHour(),'meta'=>['installed_did'=>$did]]);
  $res=[];$ctl=app(Webkul\Shop\Http\Controllers\API\SkillController::class);
  $r=$ctl->triggerExecution(Request::create('/api/skills/entitlements/'.$e->id.'/executions','POST',['params'=>['speed'=>99]]),$e->id);
  $res['expired_authorization_and_no_install']=['is_usable'=>$e->isUsable(),'http_status'=>$r->getStatusCode(),'data'=>$r->getData(true),'install_records'=>Webkul\Shop\Models\InstallRecord::where('did',$did)->count()];
  $e->update(['expires_at'=>null]);
  $ids=[];foreach([1,2]as$i){$r=$ctl->triggerExecution(Request::create('/api/skills/entitlements/'.$e->id.'/executions','POST',['params'=>['speed'=>99],'nonce'=>'same-client-retry']),$e->id);$ids[]=['status'=>$r->getStatusCode(),'execution_id'=>$r->getData(true)['data']['execution_id']??null];}
  $res['nonempty_params_same_nonce']=$ids;
  file_put_contents(__DIR__.'/customer-counterexamples.json',json_encode($res,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo json_encode($res,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;
 } finally {DB::rollBack();}
}

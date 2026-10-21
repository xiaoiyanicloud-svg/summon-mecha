<template><view class="card"><text class="section">私有现场资料</text><text class="muted">仅用于本需求评估和履约；PDF、PNG、JPEG，最多 8 MB。请勿上传无关身份或医疗资料。</text><text v-if="pending" class="notice">上次上传回执待确认，查询或重选原件会保留同一提交标识。</text><text v-if="message" role="status" class="notice">{{ message }}</text><view class="actions"><button class="secondary" :disabled="busy" @tap="choose">{{ pending ? '重选原文件并继续' : '选择并扫描上传' }}</button><button v-if="pending" class="secondary" :disabled="busy" @tap="recover">查询原上传回执</button></view><view v-for="file in files" :key="file.id" class="divider"><text>资料 #{{ file.id }} · {{ file.mime }} · {{ file.size }} 字节</text><text class="muted">{{ availability(file.availability_state) }} · 到期 {{ file.expires_at || '请查看服务记录' }} UTC</text><button v-if="file.availability_state==='available'" class="secondary" :disabled="busy" @tap="download(file)">下载并查看私有原件</button></view><text v-if="!files.length" class="muted">暂无已确认上传资料。</text></view></template>
<script>
import { sceneAccountScope, sceneAttachmentPending, recoverSceneAttachment, submitSceneAttachment, openSceneAttachment } from '@/utils/scene-request'
export default {
  props:{requestId:{type:Number,required:true},files:{type:Array,default:()=>[]}},emits:['changed'],
  data(){return {busy:false,message:'',pending:null}},
  mounted(){this.pending=sceneAttachmentPending(this.requestId)},
  methods:{
    availability(value){return ({available:'可读取',expired:'已到期，普通读取已停止',revoked:'已撤销使用资格',deletion_pending:'清理处理中',deleted:'原件已删除，保留必要引用'})[value]||'暂不可读取'},
    async perform(action){if(this.busy)return;const scope=sceneAccountScope();this.busy=true;this.message='';try{await action(scope)}catch(e){if(scope===sceneAccountScope())this.message=e.message||'资料操作未完成'}finally{if(scope===sceneAccountScope())this.pending=sceneAttachmentPending(this.requestId);this.busy=false}},
    choose(){
      const scope=sceneAccountScope()
      let picker=uni.chooseFile, options={count:1,extension:['.pdf','.png','.jpg','.jpeg']}
      // 微信使用原生文件选择；H5 chooseFile 的 type 不接受 file，后缀须带点。
      if(typeof wx!=='undefined' && typeof wx.chooseMessageFile==='function'){picker=wx.chooseMessageFile.bind(wx);options={count:1,type:'file',extension:['pdf','png','jpg','jpeg']}}
      else if(!picker){picker=uni.chooseImage;options={count:1};this.message='当前端可选择图片资料'}
      if(!picker){this.message='当前设备暂不支持选择文件';return}
      picker({...options,success:r=>{
        if(scope!==sceneAccountScope())return
        const file=r.tempFiles?.[0], path=file?.path||r.tempFilePaths?.[0]
        if(!path)return
        if(file?.size>8*1024*1024){this.message='文件超过 8 MB';return}
        this.perform(async active=>{await submitSceneAttachment(this.requestId,{path});if(active===sceneAccountScope()){this.message='上传已确认';this.$emit('changed')}})
      },fail:e=>{if(scope===sceneAccountScope()&&!String(e?.errMsg||'').includes('cancel'))this.message='选择文件失败，请重试'}})
    },
    recover(){this.perform(async scope=>{const result=await recoverSceneAttachment(this.requestId);if(scope!==sceneAccountScope())return;this.message=result?'原上传已确认，状态：'+this.availability(result.availability_state):'未查询到已提交记录，请重选原文件继续';if(result)this.$emit('changed')})},
    download(file){this.perform(()=>openSceneAttachment(this.requestId,file.id,file.mime))}
  }
}
</script>
<style scoped>@import '../pages/scene/scene.css';</style>

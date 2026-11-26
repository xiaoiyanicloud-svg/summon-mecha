<?php
require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';
$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Webkul\Shop\Services\SkillCompile\Portable\CapabilityResolver;
use Webkul\Shop\Services\SkillCompile\Portable\SourceParser;
$xml = <<<'XML'
<root BTCPP_format="4" main_tree_to_execute="B5">
  <BehaviorTree ID="B5">
    <Sequence name="seq">
      <Action ID="SM_GestureWave" name="wave" hand="left" cycles="2" timeout_ms="8000"/>
      <Action ID="SM_AudioPlay" name="audio" asset="assets/welcome.wav" wait="true" timeout_ms="4000"/>
    </Sequence>
  </BehaviorTree>
</root>
XML;
$ir = app(SourceParser::class)->parse($xml);
foreach (['unitree_g1','unitree_r1','agibot_x2','agibot_a3','engineai_t800'] as $m) {
  $r = app(CapabilityResolver::class)->resolve($ir, $m);
  echo $m, ' => ', json_encode(['compatible'=>$r['compatible'],'reasons'=>$r['reasons']]), "\n";
}

<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;

use CetechDeliveryEngine\Application\Configuration\Admin\{EntityLabelResolver,LegacyCategoryConfigurationInspector,ProductVariationScopeGuard,ScopedConfigurationAdminService,ScopedConfigurationSubmissionParser,ScopedConfigurationWriteCommand};
use CetechDeliveryEngine\Application\Configuration\{EffectiveConfigurationResolver,EffectiveConfigurationValidator,HardFulfilmentConstraintService};
use CetechDeliveryEngine\Application\Runtime\{EcrProductDeliveryConfigurationSource,EcrToRuntimeConfigurationAdapter,LegacyCategoryRuntimeCompatibilityGuardInterface,ProductDeliveryConfigurationSourceInterface,ProductDeliveryRuntimeConfigurationRouter,ProductDeliveryRuntimeResolution,RuntimeConfigurationSource};
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldRegistry;
use CetechDeliveryEngine\Domain\Enum\{ConfigurationScopeType,ConfigurationSource};
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Tests\Unit\Configuration\Admin\RecordingConfigurationAuditLogger;
use CetechDeliveryEngine\Tests\Unit\Runtime\{FixedProductTypeInspector,FixedVariationRelationshipInspector,InMemoryDeliveryOfferRepository};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure native-fixture input regression; no WordPress, SQL, browser or policy capture acceptance. */
final class CustomerPolicyNativeFixtureEcrTest extends TestCase {
    private array $before;
    protected function setUp():void {
        $this->before=$GLOBALS['cetech_de_test_options']??[];$GLOBALS['cetech_de_test_options']=[];
        ConfigurationFieldRegistry::reset_for_tests();FulfilmentProfileRegistry::reset_for_tests();
        self::load_actual_fixture_fields();
    }
    protected function tearDown():void {
        $GLOBALS['cetech_de_test_options']=$this->before;
        ConfigurationFieldRegistry::reset_for_tests();FulfilmentProfileRegistry::reset_for_tests();
    }
    public static function targets():array{return['simple'=>['product',101],'selected variation'=>['variation',303]];}
    #[DataProvider('targets')]
    public function test_actual_fixture_admin_fields_make_both_native_options_resolve_without_private_dimensions(string$target,int$id):void {
        [$repository,$resolver,$admin,$source,$offers]=$this->components();
        self::assertFalse($source->resolve($target,$id)->result->success,'An empty ECR store must reproduce unavailable options.');
        foreach([101,202]as$product){
            $result=$admin->save(new ScopedConfigurationWriteCommand(ConfigurationScopeType::Product,$product,'in_warehouse',null,\r24_runtime_fields([11,22]),true,0,'r24-fixture-ecr-'.$product,0));
            self::assertTrue($result->success,implode('; ',$result->errors));self::assertTrue($result->audit_recorded);self::assertTrue($result->version_changed);self::assertSame(0,$result->version_before);self::assertSame(1,$result->version_after);
            $row=$repository->findByScopeAndSlice(ConfigurationScopeType::Product,$product,'in_warehouse');self::assertNotNull($row);self::assertSame(ConfigurationSource::Native,$row->scope->source);self::assertSame(1,$row->scope->config_version);self::assertSame($result->saved?->fingerprint(),$row->fingerprint());
        }
        $flags=new FeatureFlags();$flags->set(ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG,true);$flags->set(ProductDeliveryRuntimeConfigurationRouter::VARIABLE_CUTOVER_FLAG,true);$router=$this->router($flags,$source);
        self::assertSame(RuntimeConfigurationSource::ECR,$router->decide($target,$id));$runtime=$router->resolve($target,$id);self::assertSame(RuntimeConfigurationSource::ECR,$runtime->source);self::assertTrue($runtime->result->success);self::assertSame($target,$runtime->result->input_target_type);self::assertSame($id,$runtime->result->input_target_id);self::assertSame(['in_warehouse'],array_keys($runtime->result->chosen_rules));
        $rule=$runtime->result->chosen_rules['in_warehouse'];self::assertSame('delivery',$rule->fulfilment_choice);self::assertSame([11,22],$rule->delivery_offer_ids);self::assertNull($rule->logistics_profile_id);self::assertNull($rule->supplier_id);self::assertNull($rule->origin_id);self::assertSame(['logistics_profile_id'=>null,'supplier_id'=>null,'origin_id'=>null],$runtime->quote_dimensions_for('in_warehouse'));
        $built=(new ProductDeliveryOptionsBuilder($offers))->buildFromResolution($runtime->result);self::assertCount(2,$built);foreach($built as$i=>$option){$offer=[11,22][$i];self::assertTrue($option->is_available);self::assertSame($offer,$option->delivery_offer_id);self::assertSame('in_warehouse:delivery:'.$offer,$option->display_key);}
        self::assertNull($repository->findByScopeAndSlice(ConfigurationScopeType::Variation,303,'in_warehouse'),'Selected variation must inherit the actual parent scope.');
        $before=$repository->findByScopeAndSlice(ConfigurationScopeType::Product,202,'in_warehouse')?->fingerprint();$stale=$admin->save(new ScopedConfigurationWriteCommand(ConfigurationScopeType::Product,202,'in_warehouse',null,\r24_runtime_fields([22,11]),true,0,'r24-fixture-stale',0));self::assertFalse($stale->success);self::assertSame($before,$repository->findByScopeAndSlice(ConfigurationScopeType::Product,202,'in_warehouse')?->fingerprint());
    }
    public function test_actual_router_requires_both_cutovers_for_selected_variation():void {
        [,,,$source]=$this->components();$flags=new FeatureFlags();$router=$this->router($flags,$source);
        self::assertSame(RuntimeConfigurationSource::LEGACY,$router->decide('product',101));self::assertSame(RuntimeConfigurationSource::LEGACY,$router->decide('variation',303));$flags->set(ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG,true);self::assertSame(RuntimeConfigurationSource::ECR,$router->decide('product',101));self::assertSame(RuntimeConfigurationSource::LEGACY,$router->decide('variation',303));$flags->set(ProductDeliveryRuntimeConfigurationRouter::VARIABLE_CUTOVER_FLAG,true);self::assertSame(RuntimeConfigurationSource::ECR,$router->decide('variation',303));$flags->set(ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG,false);self::assertSame(RuntimeConfigurationSource::LEGACY,$router->decide('variation',303));
    }
    private function components():array {
        $repository=new InMemoryScopedConfigurationRepository();$offers=new InMemoryDeliveryOfferRepository();foreach([11,22]as$id)$offers->seed($id,['status'=>'active','public_label'=>'Owned R24 delivery '.$id,'route'=>'local_delivery']);$resolver=new EffectiveConfigurationResolver($repository,new EffectiveConfigurationValidator(),new HardFulfilmentConstraintService($offers));$relationships=new FixedVariationRelationshipInspector([303=>202]);$admin=new ScopedConfigurationAdminService($repository,$resolver,new ScopedConfigurationSubmissionParser(),new ProductVariationScopeGuard(),new EntityLabelResolver($offers),new LegacyCategoryConfigurationInspector(),new RecordingConfigurationAuditLogger(),$relationships->belongs_to_parent(...));$source=new EcrProductDeliveryConfigurationSource($resolver,new EcrToRuntimeConfigurationAdapter(),$relationships);return[$repository,$resolver,$admin,$source,$offers];
    }
    private function router(FeatureFlags$flags,EcrProductDeliveryConfigurationSource$source):ProductDeliveryRuntimeConfigurationRouter {
        $legacy=new class implements ProductDeliveryConfigurationSourceInterface{public function resolve(string$type,int$id):ProductDeliveryRuntimeResolution{throw new \LogicException('Regression unexpectedly executed a legacy fallback.');}};$guard=new class implements LegacyCategoryRuntimeCompatibilityGuardInterface{public function depends_on_legacy_category_rule(int$id):bool{return false;}public function depends_on_legacy_category_rule_for_target(string$type,int$id):bool{return false;}};
        return new ProductDeliveryRuntimeConfigurationRouter($flags,$legacy,$source,$guard,new FixedProductTypeInspector([101=>'simple',202=>'variable',303=>'variation']),$legacy,new FixedVariationRelationshipInspector([303=>202]));
    }
    /** Extract exactly the pure helper, without executing the CI/WP bootstrap file. */
    private static function load_actual_fixture_fields():void {
        if(function_exists('r24_runtime_fields'))return;$path=dirname(__DIR__,3).'/scripts/qualification/customer-policy-native.php';$tokens=token_get_all((string)file_get_contents($path));$declarations=[];
        foreach($tokens as$i=>$token){if(!is_array($token)||T_FUNCTION!==$token[0])continue;$j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&T_WHITESPACE===$tokens[$j][0])++$j;if(!isset($tokens[$j])||!is_array($tokens[$j])||T_STRING!==$tokens[$j][0]||'r24_runtime_fields'!==$tokens[$j][1])continue;$code='';$depth=0;$opened=false;for($k=$i;$k<count($tokens);++$k){$part=$tokens[$k];$code.=is_array($part)?$part[1]:$part;if(is_array($part))continue;if('{'===$part){++$depth;$opened=true;}elseif('}'===$part){--$depth;if($opened&&0===$depth){$declarations[]=$code;break;}}}}
        self::assertCount(1,$declarations,'Exactly one actual pure fixture field declaration is required.');eval('namespace { '.$declarations[0].' }');
    }
}

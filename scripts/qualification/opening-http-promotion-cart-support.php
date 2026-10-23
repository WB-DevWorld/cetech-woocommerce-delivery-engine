<?php
/** N18 trusted composition on the marked disposable native site; no default merchant policy. */
declare(strict_types=1);

require_once __DIR__ . '/opening-quote-cart-support.php';

use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionCartPlanning;
use CetechDeliveryEngine\Application\DeliveryQuote\{CartQuoteService, FixedPromotionQuoteAcceptService, FixedPromotionQuoteIssueService, FixedPromotionQuotePlacementEvidenceReader, FixedPromotionQuoteProvider, NativeCartQuoteEnvironment, NativeCartQuotePreparation, NativeCartQuoteSessionStore, QuotePreparationGate};
use CetechDeliveryEngine\Application\RuleLifecycle\RuleLifecycleService;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingRateCalculator;
use CetechDeliveryEngine\Bootstrap\Plugin;
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedDeliveryPromotion, FixedPromotionBudgetRequest, FixedPromotionBudgetState, FixedPromotionEligibilityFamily};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteId, QuoteMoney, QuoteOwner};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationSession};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent, RuleFamilyRegistry, RuleLifecycleCommand};
use CetechDeliveryEngine\Infrastructure\Persistence\{SqlFixedPromotionBudgetStore, SqlFixedPromotionRuleEligibilityGuard, TableNames};

final class CetechPromotionCartHttpFixture {
    /** Replace trusted fixture bindings before plugin hook registration; no option enables this path. */
    public static function mount($container, wpdb $db, array $state): void {
        [$service,$sessions,$factory,$environment,$store,$eligibility,$reader,$configure]=self::service($db,$state);
        $replace=static function(string $id, $value) use ($container): void { $container->bind($id,static fn()=>$value); $container->singleton($id,static fn()=>$value); };
        foreach ([\CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory::class=>$factory, NativeCartQuoteEnvironment::class=>$environment, NativeCartQuoteSessionStore::class=>$sessions, \CetechDeliveryEngine\Application\DeliveryQuote\CartQuoteReviewService::class=>$service] as $id=>$value) { $replace($id,$value); }
        $stager=new \CetechDeliveryEngine\Application\Order\QuoteNativeOrderStager($factory);
        $authorize=static function(WC_Order $order): bool {
            // The exact disposable creation hook publishes IDs before any handoff effect.
            $id=$order->get_id(); return $id>0 && in_array($id,$GLOBALS['cetech_n18_owned_orders']??[],true) && $order->get_customer_id()===(int)($GLOBALS['current_user']->ID??0);
        };
        $GLOBALS['cetech_n18_owned_orders']=$state['promotion']['orders'];
        $auth=[$environment,'authorize'];
        $saved=new \CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionSavedOrderPlacementEvidenceReader($factory,$authorize,$auth);
        $promotion=new \CetechDeliveryEngine\Integrations\DeliveryQuote\FixedPromotionNativePlacementRuntime($factory,$stager,
            new \CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteBindService($factory,$store,$eligibility,$auth),
            new \CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteVerifyService($factory,$store,$eligibility,$auth),
            new \CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteSealService($factory,$store,$eligibility,$auth),
            $reader,$saved,[$service,'promotion_placement'],$authorize,
            selected:static fn():bool=>FixedPromotionQuoteProvider::CODE===$environment->profile());
        $container->bind(\CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime::class,static fn($c)=>new \CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime($factory,
            new \CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartPlacementEvidenceReader($environment,$sessions,$factory),$stager,
            $c->get(\CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutQuoteValidator::class),static fn():bool=>false,static fn():bool=>true,final_admission:static fn($order,$route)=>$c->get(\CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutAdmissionService::class)->final_order($order,$route),promotion:$promotion));
        $container->singleton(\CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime::class,static fn($c)=>new \CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime($factory,
            new \CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartPlacementEvidenceReader($environment,$sessions,$factory),$stager,
            $c->get(\CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutQuoteValidator::class),static fn():bool=>false,static fn():bool=>true,final_admission:static fn($order,$route)=>$c->get(\CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutAdmissionService::class)->final_order($order,$route),promotion:$promotion));
        $replace(\CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteReviewRuntime::class,new \CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteReviewRuntime($service,true));
        $rates=new \CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteRateReferenceRuntime($environment,$sessions,true); $environment->set_rate_projection($rates);
        $replace(\CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteRateReferenceRuntime::class,$rates);
        $GLOBALS['cetech_n18_cart_service']=$service; $GLOBALS['cetech_n18_environment']=$environment; $GLOBALS['cetech_n18_promotion_runtime']=$promotion; $GLOBALS['cetech_n18_saved_reader']=$saved;
        $GLOBALS['cetech_q05_review_runtime']=$container->get(\CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteReviewRuntime::class); $GLOBALS['cetech_q05_sessions']=$sessions; $GLOBALS['cetech_q05_factory']=$factory;
        add_action('init',$configure,90);
        add_action('woocommerce_new_order',static function(int $id, WC_Order $order):void {
            if (true!==($GLOBALS['cetech_n18_creating_order']??false)) { return; }
            $state=cetech_q05_state(); if (count($state['promotion']['orders'])>=4) { throw new RuntimeException('N18 owned order cap exceeded.'); }
            $state['promotion']['orders'][]=$id; $state['promotion']['orders']=array_values(array_unique($state['promotion']['orders'])); cetech_q05_write($state);
            $GLOBALS['cetech_n18_owned_orders']=$state['promotion']['orders']; $GLOBALS['cetech_n18_created_order']=$order;
        },0,2);
        add_action('shutdown',static function()use($sessions,$factory):void {
            $state=null; $tracked=false;
            try {
                $state=cetech_q05_state();
                if (WC()->session instanceof WC_Session_Handler&&WC()->cart instanceof WC_Cart&&WC()->customer instanceof WC_Customer) { CetechQuoteCartHttpFixture::track_owner($state,$sessions); }
                $tracked=true;
            } finally {
                $retired=$factory->close_all();
                if (is_array($state)) { $state['promotion']['all_owners_retired']=$tracked&&$retired&&true===($state['promotion']['all_owners_retired']??true); cetech_q05_write($state); }
            }
        },19);
    }
    public static function family(array $state): FixedPromotionEligibilityFamily {
        $site = $state['site_id']; $author = $state['promotion']['author'];
        return new FixedPromotionEligibilityFamily(
            static fn(OperationIdentity $id): bool => $id->site_id === $site && $id->principal === 'user:' . $author && in_array($id->operation, ['rule.draft.create','rule.publish'], true),
            static fn(int $candidate_site, int $candidate_author): bool => $candidate_site === $site && $candidate_author === $author
        );
    }
    /** Native create_order CRUD and production C07, deliberately ending before any gateway. */
    public static function place(wpdb $db, array &$state): array {
        $request=RequestContext::create(); $service=$GLOBALS['cetech_n18_cart_service'];
        $bundle=$service->promotion_placement($request); if (null===$bundle) { throw new RuntimeException('N18 current accepted cart proof unavailable.'); }
        $evidence=$bundle['evidence']; $placement=\CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementService::placement_id_for($evidence->owner(),$evidence->header());
        $state['promotion']['stage_namespaces']=$state['promotion']['stage_namespaces']??[];
        foreach (['delivery_quote.bind'=>1,'delivery_quote.verify_binding'=>1,'delivery_quote.seal'=>2] as $operation=>$version) {
            $id=new OperationIdentity($state['site_id'],'delivery_quote_customer',$evidence->owner()->digest(),$operation,$version,'placement:'.$placement,$evidence->header()->namespace_hashes()['issue']);
            $state['promotion']['stage_namespaces'][]=$id->namespace_digest();
        }
        $state['promotion']['stage_namespaces']=array_values(array_unique($state['promotion']['stage_namespaces']));
        if (count($state['promotion']['stage_namespaces'])>12) { throw new RuntimeException('N18 owned namespace cap exceeded.'); }
        cetech_q05_write($state); // Exact deterministic namespaces are cleanup authority before effects.
        $before=self::budget_rows($db,$state); $GLOBALS['cetech_n18_creating_order']=true; unset($GLOBALS['cetech_n18_created_order']);
        try {
            $customer=WC()->customer; $data=['billing_first_name'=>'Owned','billing_last_name'=>'Fixture','billing_email'=>'n18@example.invalid','billing_country'=>'GH','billing_state'=>'AA','billing_city'=>'Accra','billing_postcode'=>'00001','billing_address_1'=>$customer->get_shipping_address_1(),'shipping_first_name'=>'Owned','shipping_last_name'=>'Fixture','shipping_country'=>$customer->get_shipping_country(),'shipping_state'=>$customer->get_shipping_state(),'shipping_city'=>$customer->get_shipping_city(),'shipping_postcode'=>$customer->get_shipping_postcode(),'shipping_address_1'=>$customer->get_shipping_address_1(),'shipping_address_2'=>$customer->get_shipping_address_2(),'ship_to_different_address'=>1,'payment_method'=>''];
            $id=WC()->checkout()->create_order($data);
            if (is_wp_error($id)||!is_int($id)||$id<1) { throw new RuntimeException('N18 actual native order creation failed.'); }
            $order=$GLOBALS['cetech_n18_created_order']??null;
            if (!$order instanceof WC_Order||$order->get_id()!==$id) { throw new RuntimeException('N18 native creation identity unavailable.'); }
        } finally { $GLOBALS['cetech_n18_creating_order']=false; }
        $runtime=$GLOBALS['cetech_n18_promotion_runtime']; $runtime->stage($order,'classic',$request);
        $decision=Plugin::instance()->container()->get(\CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutAdmissionService::class)->final_order($order,'classic');
        $sealed=$runtime->sealed($order); $history=$GLOBALS['cetech_n18_saved_reader']->read($order,RequestContext::create());
        $package=(new \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader())->read_package($order);
        $state=cetech_q05_state(); $state['promotion']['placed_order']=$id; cetech_q05_write($state);
        return ['native_order_created'=>true,'pending_unpaid'=>$order->has_status('pending')&&!$order->is_paid()&&null===$order->get_date_paid(),'sealed3_current'=>$sealed instanceof \CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteSealedEvidence,'history_all_five'=>is_array($history)&&null!==$history['seal']&&3===$history['binding']->revision(),'saved_format3'=>3===$package->delivery_quote?->envelope?->format(),'gateway_refused'=>false===$decision->allowed&&0===(int)get_option('cetech_opening_c07_gateway_count',0),'held_budget_unchanged'=>$before===self::budget_rows($db,$state)];
    }
    public static function placed_history(array $state): array {
        $id=$state['promotion']['placed_order']??0; $order=is_int($id)&&$id>0?wc_get_order($id):null;
        $history=$order instanceof WC_Order?$GLOBALS['cetech_n18_saved_reader']->read($order,RequestContext::create()):null;
        $control=Plugin::instance()->container()->get(\CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlService::class)->read($state['site_id']);
        $current=$order instanceof WC_Order&&$control->available&&null!==$control->state?$GLOBALS['cetech_n18_promotion_runtime']->current_sealed($order,$control->state->revision,RequestContext::create()):null;
        return ['fresh_native_order'=>$order instanceof WC_Order,'original_five_authenticated'=>is_array($history)&&null!==$history['seal']&&3===$history['binding']->revision(),'fresh_current_sealed'=>$current instanceof \CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteSealedEvidence,'pending_unpaid'=>$order instanceof WC_Order&&$order->has_status('pending')&&!$order->is_paid()&&null===$order->get_date_paid(),'gateway_zero'=>0===(int)get_option('cetech_opening_c07_gateway_count',0)];
    }
    private static function budget_rows(wpdb $db,array $state):array {
        $rows=[]; foreach (['promotion_budget_accounts','promotion_budget_reservations'] as $suffix) {
            $found=$db->get_results($db->prepare('SELECT * FROM `'.TableNames::for($suffix).'` WHERE site_id=%d AND account_key=%s ORDER BY id',$state['site_id'],$state['promotion']['account']),ARRAY_A);
            if (!is_array($found)||''!==$db->last_error) { throw new RuntimeException('N18 owned ledger observation unavailable.'); } $rows[$suffix]=$found;
        } return $rows;
    }
    /** The same installed calculator used by the native shipping method gets the temporary scope. */
    public static function service(wpdb $db, array $state): array {
        $factory = new CetechQuoteCartFactory($db);
        $environment = new NativeCartQuoteEnvironment($factory, profile_selector: static fn(): string => FixedPromotionQuoteProvider::CODE);
        $auth = [$environment, 'authorize']; $store = new SqlFixedPromotionBudgetStore();
        $eligibility = new SqlFixedPromotionRuleEligibilityGuard(self::family($state));
        $planning = new FixedPromotionCartPlanning($factory, $eligibility, $store, static fn(QuoteOwner $owner): bool => $environment->authorize($owner, 'delivery_quote.read'));
        $rule = FixedDeliveryPromotion::from_array($state['promotion']['rule']);
        $rules = static function(QuoteOwner $owner, $context, $terms) use ($rule, $state): array {
            if ($owner->site_id() !== $state['site_id']) { throw new RuntimeException('N18 selection refused.'); }
            $slots = []; foreach ($context->private_facts()['groups'] as $group) {
                $subject = FixedPromotionEligibilityFamily::subject_for_component($context, $terms, $group['component_key'], $rule->code());
                if (!self::family($state)->match($state['promotion']['scope'], $subject)->matched) { throw new RuntimeException('N18 explicit fixture scope differs.'); }
                $slots[] = ['component_key' => $group['component_key'], 'rule' => $rule];
            }
            return $slots;
        };
        $configure = static function() use ($environment, $factory, $planning, $rules): void { $environment->set_preparation(new NativeCartQuotePreparation($factory,
            promotion_planning: $planning,
            promotion_rates: Plugin::instance()->container()->get(SelectedOfferShippingRateCalculator::class),
            promotion_rules: $rules, promotion_hold_seconds: 300)); }; // Declared fixture lease only; install after container construction.
        $sessions = new NativeCartQuoteSessionStore($factory, $auth);
        $budget_auth = static fn(OperationSession $session, FixedPromotionBudgetRequest $request, string $action): bool => 'reserve' === $action && $session->site_id() === $state['site_id'] && $request->account_key() === $state['promotion']['account'] && $request->funding_source() === $rule->private_facts()['funding_source'];
        $reader = new FixedPromotionQuotePlacementEvidenceReader($factory, $store, $eligibility, $auth);
        $service = new CartQuoteService($environment, new QuotePreparationGate($factory, $auth), $sessions, $factory,
            promotion_issuer: static fn($registry) => new FixedPromotionQuoteIssueService($factory, $registry, $store, $eligibility, $auth, $budget_auth),
            promotion_accept: new FixedPromotionQuoteAcceptService($factory, $store, $eligibility, $auth), promotion_reader: $reader);
        return [$service, $sessions, $factory, $environment, $store, $eligibility, $reader, $configure];
    }
    public static function prepare(wpdb $db, array &$state): void {
        if (isset($state['promotion'])) { throw new RuntimeException('N18 refuses replacing owned policy.'); }
        $authors = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        if (1 !== count($authors) || (int)$authors[0] < 1) { throw new RuntimeException('N18 fixture author is absent.'); }
        $products = array_values($state['native']['products']); sort($products, SORT_NUMERIC);
        $precision = wc_get_rounding_precision(); if (!is_int($precision)||$precision<0||$precision>6) { throw new RuntimeException('N18 actual native rounding precision unavailable.'); } $precision=max(4,$precision);
        $rule = FixedDeliveryPromotion::from_array(['format_version'=>1,'code'=>'n18_owned_cart','version'=>1,'discount'=>['amount'=>'3.0000','currency'=>'GHS','precision'=>$precision],'funding_source'=>['code'=>'n18_owned_pool','version'=>1]]);
        $state['promotion'] = ['precision'=>$precision, 'author'=>(int)$authors[0], 'account'=>hash('sha256','N18 owned pool:' . $state['fixture_token']), 'rule'=>$rule->private_facts(), 'scope'=>['promotion_code'=>$rule->code(),'offer_id'=>$state['native']['offer'],'destination_zone_id'=>$state['native']['zone'],'currency'=>'GHS','product_ids'=>$products], 'logical_uuid'=>QuoteId::generate()->value(), 'version_uuid'=>QuoteId::generate()->value(), 'namespaces'=>[], 'before'=>[], 'orders'=>[]];
        foreach (['rule_family_guards','logical_rules','rule_versions','promotion_budget_accounts','promotion_budget_reservations'] as $suffix) { $state['promotion']['before'][$suffix] = CetechNativeQuoteProviderFixture::rows(TableNames::for($suffix)); }
        // Persist cleanup authority before any provision/publication effect.
        opening_http_write_json($state['state_path'], $state, true);
        $factory = new CetechQuoteCartFactory($db); $store = new SqlFixedPromotionBudgetStore(); $session = $factory->open();
        try {
            if (!$session->begin() || !$session->validate_tables($store->tables($session)) || !$store->insert_account($session, FixedPromotionBudgetState::create($state['site_id'], $state['promotion']['account'], $rule->private_facts()['funding_source'], QuoteMoney::from_array(['amount'=>'1000.0000','currency'=>'GHS','precision'=>$precision]))) || OperationCommitResult::Acknowledged !== $session->commit()) { throw new RuntimeException('N18 owned funding setup failed.'); }
        } finally { if ($session->in_transaction()) { $session->rollback(); } if (!$session->retire() || !$factory->close_all()) { throw new RuntimeException('N18 setup owner retirement failed.'); } }
        $family = self::family($state); $service = new RuleLifecycleService(new RuleFamilyRegistry([$family]), $factory);
        $prior = $db->get_row($db->prepare('SELECT * FROM `' . TableNames::for('rule_family_guards') . '` WHERE site_id=%d AND family_code=%s', $state['site_id'], $family->family()), ARRAY_A);
        if ('' !== $db->last_error) { throw new RuntimeException('N18 policy guard unavailable.'); }
        $payload = ['family'=>$family->family(),'logical_uuid'=>$state['promotion']['logical_uuid'],'version_uuid'=>$state['promotion']['version_uuid'],'scope'=>$state['promotion']['scope'],'payload'=>['rule'=>$rule->private_facts(),'budget_account_key'=>$state['promotion']['account']],'start_mode'=>'immediate','effective_from'=>null,'effective_until'=>null,'priority'=>10,'author_user_id'=>$state['promotion']['author'],'change_reason'=>'Declared disposable N18 HTTP fixture only','predecessor_uuid'=>null,'preconditions'=>['family_revision'=>(int)($prior['revision']??0),'logical_id'=>0,'logical_revision'=>0,'version_id'=>0,'version_revision'=>0,'predecessor_id'=>0,'predecessor_revision'=>0]];
        foreach (['rule.draft.create','rule.publish'] as $operation) {
            if ('rule.publish' === $operation) {
                $logical = $db->get_row($db->prepare('SELECT * FROM `' . TableNames::for('logical_rules') . '` WHERE site_id=%d AND logical_uuid=%s', $state['site_id'], $state['promotion']['logical_uuid']), ARRAY_A);
                $version = $db->get_row($db->prepare('SELECT * FROM `' . TableNames::for('rule_versions') . '` WHERE site_id=%d AND version_uuid=%s', $state['site_id'], $state['promotion']['version_uuid']), ARRAY_A);
                $guard = $db->get_row($db->prepare('SELECT * FROM `' . TableNames::for('rule_family_guards') . '` WHERE id=%d', (int)($logical['family_guard_id']??0)), ARRAY_A);
                if (!is_array($logical)||!is_array($version)||!is_array($guard)||''!==$db->last_error) { throw new RuntimeException('N18 created policy unavailable.'); }
                $payload['preconditions'] = ['family_revision'=>(int)$guard['revision'],'logical_id'=>(int)$logical['id'],'logical_revision'=>(int)$logical['revision'],'version_id'=>(int)$version['id'],'version_revision'=>(int)$version['row_revision'],'predecessor_id'=>0,'predecessor_revision'=>0];
            }
            $identity = new OperationIdentity($state['site_id'],'wordpress','user:'.$state['promotion']['author'],$operation,1,RuleLifecycleCommand::make_target_key($family->family(),RuleContent::scope_hash($family,$payload['scope']),$payload['logical_uuid']),'n18:'.$operation.':'.$state['promotion']['logical_uuid']);
            $state['promotion']['namespaces'][] = $identity->namespace_digest(); opening_http_write_json($state['state_path'],$state,true);
            $result = $service->attempt($identity,$payload,RequestContext::create());
            if ('accepted' !== $result->outcome->state) { throw new RuntimeException('N18 native policy publication failed.'); }
        }
        if (!$factory->close_all()) { throw new RuntimeException('N18 policy owner retirement failed.'); }
        $state['promotion_expected_money'] = ['currency'=>'GHS','list_price'=>QuoteMoney::from_array(['amount'=>'7','currency'=>'GHS','precision'=>$precision])->amount(),'promotion'=>$rule->discount()->amount(),'final_price'=>QuoteMoney::from_array(['amount'=>'4','currency'=>'GHS','precision'=>$precision])->amount(),'display_total'=>'4.40'];
        opening_http_write_json($state['state_path'],$state,true);
        $browser=$state; unset($browser['native'],$browser['owners'],$browser['promotion'],$browser['identity']['installed_php_sources']);
        opening_http_write_json($state['browser_state_path'],$browser,true);
    }
    public static function cleanup(wpdb $db, array &$state): array {
        if (!isset($state['promotion'])) { return ['no_promotion_allocation'=>true]; }
        $p=$state['promotion']; $ok=true===($p['all_owners_retired']??false);
        foreach ($p['orders'] as $id) { $order=wc_get_order($id); if ($order) { $order->delete(true); } if (wc_get_order($id)) { $ok=false; } }
        foreach (['promotion_budget_reservations','promotion_budget_accounts'] as $suffix) { $ok=false!==$db->delete(TableNames::for($suffix),['site_id'=>$state['site_id'],'account_key'=>$p['account']])&&$ok; }
        foreach (['rule_versions'=>'version_uuid','logical_rules'=>'logical_uuid'] as $suffix=>$column) { $ok=false!==$db->delete(TableNames::for($suffix),['site_id'=>$state['site_id'],$column=>$p[$column]])&&$ok; }
        $guards=$p['before']['rule_family_guards']; $before=null; foreach ($guards as $row) { if ((int)$row['site_id']===$state['site_id']&&FixedPromotionEligibilityFamily::CODE===$row['family_code']) { $before=$row; } }
        $ok=false!==$db->delete(TableNames::for('rule_family_guards'),['site_id'=>$state['site_id'],'family_code'=>FixedPromotionEligibilityFamily::CODE])&&$ok;
        if (null!==$before) { $ok=false!==$db->replace(TableNames::for('rule_family_guards'),$before)&&$ok; }
        foreach ($p['namespaces'] as $namespace) {
            $ids=$db->get_col($db->prepare('SELECT id FROM `'.TableNames::for('operation_records').'` WHERE site_id=%d AND namespace_hash=%s AND operation IN (%s,%s)',$state['site_id'],$namespace,'rule.draft.create','rule.publish'));
            foreach ($ids as $id) { $ok=false!==$db->delete(TableNames::for('operation_changes'),['site_id'=>$state['site_id'],'operation_id'=>(int)$id])&&$ok; $ok=false!==$db->delete(TableNames::for('operation_records'),['site_id'=>$state['site_id'],'id'=>(int)$id])&&$ok; }
        }
        foreach ($p['stage_namespaces']??[] as $namespace) {
            $ids=$db->get_col($db->prepare('SELECT id FROM `'.TableNames::for('operation_records').'` WHERE site_id=%d AND namespace_hash=%s AND operation IN (%s,%s,%s)',$state['site_id'],$namespace,'delivery_quote.bind','delivery_quote.verify_binding','delivery_quote.seal'));
            foreach ($ids as $id) { $ok=false!==$db->delete(TableNames::for('operation_changes'),['site_id'=>$state['site_id'],'operation_id'=>(int)$id])&&$ok; $ok=false!==$db->delete(TableNames::for('operation_records'),['site_id'=>$state['site_id'],'id'=>(int)$id])&&$ok; }
        }
        $steps_acknowledged=$ok; $tables_restored=[];
        foreach ($p['before'] as $suffix=>$rows) { $tables_restored[$suffix]=$rows===CetechNativeQuoteProviderFixture::rows(TableNames::for($suffix)); $ok=$tables_restored[$suffix]&&$ok; }
        // Private, boolean-only diagnostics do not replace the original cleanup receipt.
        opening_http_write_json($state['state_path'].'.promotion-cleanup-observation.json',[
            'format'=>'cetech-n18-promotion-cleanup-observation-v1',
            'owners_retired'=>true===($p['all_owners_retired']??false),
            'steps_acknowledged'=>$steps_acknowledged,'tables_restored'=>$tables_restored,
        ],true);
        return ['tracked_promotion_cleanup_restored'=>$ok];
    }
}

if (defined('WP_CLI') && WP_CLI && isset($args) && is_array($args)) {
    require_once __DIR__.'/opening-http-fixture-common.php'; opening_http_fixture_guard();
    if (3!==count($args)||!in_array($args[0],['preparepromotioncart','cleanuppromotioncart'],true)) { throw new RuntimeException('N18 fixture requires mode, private state and output.'); }
    [$mode,$path,$output]=$args; $state=opening_http_read_state($path); global $wpdb;
    if ('preparepromotioncart'===$mode) { CetechPromotionCartHttpFixture::prepare($wpdb,$state); opening_http_write_json($output,['owned_policy_published'=>true,'explicit_test_only_funding'=>true,'public_default_off'=>true]); }
    else { opening_http_write_json($output,CetechPromotionCartHttpFixture::cleanup($wpdb,$state)); }
}

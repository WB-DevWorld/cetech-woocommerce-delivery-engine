<?php
/** Bounded fixture-only observation of the existing native callback. */
declare(strict_types=1);

function cetech_n18_callback_error_class(Throwable $error): string {
    $class=get_class($error);
    return in_array($class,['Exception','RuntimeException','LogicException','InvalidArgumentException','UnexpectedValueException','Error','TypeError','ParseError'],true)?$class:'OtherError';
}

/** Exact source pins prevent a shifted or foreign callsite from acquiring a label. */
function cetech_n18_stage_source_maps(): array {
    return [
        'CetechDeliveryEngine\\Integrations\\DeliveryQuote\\FixedPromotionNativePlacementRuntime'=>[
            'path'=>'src/Integrations/DeliveryQuote/FixedPromotionNativePlacementRuntime.php',
            'sha256'=>'578125ce373452955f9b13875f6cb401c7db21ee6441ae95efc90caccb7c933b',
            'throw_line'=>127,
            'sites'=>[33=>'stage_authorization',34=>'existing_stage',37=>'saved_history',41=>'saved_resume',42=>'saved_inputs',45=>'saved_bind',49=>'current_bundle',51=>'original_bind',53=>'fresh_bundle',55=>'native_capture',57=>'bind_identity',59=>'bind_ack',64=>'verify_capture',66=>'verify_ack',69=>'local_binding',104=>'bundle_shape',109=>'native_coordinate',110=>'native_pointer',112=>'native_session'],
        ],
        'CetechDeliveryEngine\\Application\\Order\\QuoteNativeOrderStager'=>[
            'path'=>'src/Application/Order/QuoteNativeOrderStager.php',
            'sha256'=>'d37e2799bc3dc4dc35901db9bcea2d187aac631a415a7fd187c2d74f310c64bf',
            'throw_line'=>269,
            'sites'=>[38=>'stage_input',39=>'stage_actor',41=>'stage_status',43=>'stage_selection',46=>'stage_native',47=>'stage_write_guard',171=>'plan_accepted',172=>'plan_tax_source',178=>'plan_draft',182=>'plan_draft',183=>'plan_draft',185=>'plan_reference',190=>'plan_currency',191=>'plan_destination',192=>'plan_destination',195=>'plan_shipping_method',197=>'plan_shipping_terms',202=>'plan_shipping_tax_map',204=>'plan_shipping_tax_map',207=>'plan_shipping_tax',212=>'plan_shipping_totals',223=>'plan_line_mapping',226=>'plan_line_context',227=>'plan_line_destination'],
        ],
    ];
}

function cetech_n18_closed_callback_observation(mixed $value): ?array {
    if (!is_array($value)||array_keys($value)!==['format','error_class','cause_class','guard_site']||'cetech-n18-stage-classic-failure-v1'!==$value['format']) { return null; }
    $classes=['Exception','RuntimeException','LogicException','InvalidArgumentException','UnexpectedValueException','Error','TypeError','ParseError','OtherError'];
    $sites=['unknown']; foreach (cetech_n18_stage_source_maps() as $map) { foreach ($map['sites'] as $site) { $sites[]=$site; } }
    if (!in_array($value['error_class'],$classes,true)||!in_array($value['cause_class'],$classes,true)||!in_array($value['guard_site'],$sites,true)) { return null; }
    return $value;
}

/** This accepts detached trace facts only to project a fixed enum, never authority. */
function cetech_n18_stage_guard_site(string $file,int $line,array $frame): string {
    if (($frame['function']??null)!=='fail'||($frame['type']??null)!=='::'||!is_string($frame['class']??null)) { return 'unknown'; }
    $map=cetech_n18_stage_source_maps()[$frame['class']]??null;
    if (null===$map||$line!==$map['throw_line']||!is_int($frame['line']??null)||!isset($map['sites'][$frame['line']])) { return 'unknown'; }
    if (!defined('CETECH_DE_PATH')||!is_string(CETECH_DE_PATH)||!is_dir(CETECH_DE_PATH)||!class_exists($frame['class'],false)) { return 'unknown'; }
    $root=realpath(CETECH_DE_PATH); $actual=realpath($file); $declared=(new ReflectionClass($frame['class']))->getFileName();
    if (false===$root||false===$declared) { return 'unknown'; } $expected=$root.'/'.$map['path'];
    if (false===$actual||$actual!==realpath($expected)||realpath($declared)!==$actual||($frame['file']??null)!==$file||!is_file($actual)||is_link($file)||filesize($actual)>131072||!hash_equals($map['sha256'],hash_file('sha256',$actual))) { return 'unknown'; }
    return $map['sites'][$frame['line']];
}

function cetech_n18_stage_callback_observation(Throwable $error): array {
    $outer=cetech_n18_callback_error_class($error); $cause=$outer; $site='unknown'; $seen=[];
    for ($depth=0;$depth<8;++$depth) {
        $id=spl_object_id($error); if (isset($seen[$id])) { break; } $seen[$id]=true;
        $cause=cetech_n18_callback_error_class($error); $frame=$error->getTrace()[0]??[];
        $candidate=cetech_n18_stage_guard_site($error->getFile(),$error->getLine(),is_array($frame)?$frame:[]);
        if ('unknown'!==$candidate) { $site=$candidate; }
        $previous=$error->getPrevious(); if (null===$previous) { break; } $error=$previous;
    }
    return ['format'=>'cetech-n18-stage-classic-failure-v1','error_class'=>$outer,'cause_class'=>$cause,'guard_site'=>$site];
}

/** The original callback is invoked once, and its exact Throwable is rethrown. */
function cetech_n18_stage_callback_wrapper(callable $original,callable $project): Closure {
    return static function(mixed $order) use ($original,$project): mixed {
        $GLOBALS['cetech_n18_callback_status']='entered';
        try { $result=$original($order); $GLOBALS['cetech_n18_callback_status']='completed'; return $result; }
        catch (Throwable $error) {
            $GLOBALS['cetech_n18_callback_status']='threw';
            try {
                if (true===($GLOBALS['cetech_n18_creating_order']??false)&&cetech_n18_placement_tracked_order($order)) {
                    $GLOBALS['cetech_n18_callback_observation']=cetech_n18_closed_callback_observation($project($error));
                }
            } catch (Throwable) { $GLOBALS['cetech_n18_callback_observation']=null; }
            throw $error;
        }
    };
}

/** Preserve the exact WP_Hook slot, priority, sibling order and accepted arity. */
function cetech_n18_install_stage_callback_observer(): Closure {
    $hook=$GLOBALS['wp_filter']['woocommerce_checkout_order_created']??null; $priority=PHP_INT_MAX-1;
    if (!is_object($hook)||get_class($hook)!=='WP_Hook'||!is_array($hook->callbacks??null)||count($hook->callbacks)>256||!is_array($hook->callbacks[$priority]??null)) { throw new RuntimeException('N18 native callback observation unavailable.'); }
    $matches=[]; $total=0;
    foreach ($hook->callbacks as $at=>$entries) {
        if (!is_array($entries)||count($entries)>256-$total) { throw new RuntimeException('N18 native callback observation unavailable.'); } $total+=count($entries);
        foreach ($entries as $key=>$entry) {
            $call=$entry['function']??null;
            if (is_array($call)&&array_is_list($call)&&count($call)===2&&is_object($call[0])&&get_class($call[0])==='CetechDeliveryEngine\\Integrations\\DeliveryQuote\\QuotePlacementRuntime'&&$call[1]==='stage_classic') { $matches[]=[$at,$key,$entry]; }
        }
    }
    if (count($matches)!==1||$matches[0][0]!==$priority||($matches[0][2]['accepted_args']??null)!==1) { throw new RuntimeException('N18 native callback observation unavailable.'); }
    [, $key, $entry]=$matches[0];
    $hook->callbacks[$priority][$key]['function']=cetech_n18_stage_callback_wrapper($entry['function'],'cetech_n18_stage_callback_observation');
    $GLOBALS['cetech_n18_callback_status']='installed';
    return static function() use ($hook,$priority,$key,$entry): void { $hook->callbacks[$priority][$key]=$entry; };
}

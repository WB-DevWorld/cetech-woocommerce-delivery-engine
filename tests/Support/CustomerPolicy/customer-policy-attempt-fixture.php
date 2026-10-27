<?php
declare(strict_types=1);
// Declared native CRUD acknowledgement port for the existing in-memory SQL fixture.
// Claims are genuinely issued at id0 / checkout-draft, before this port saves the marker.
function writer_native_rows(WC_Order$order,WC_Order_Item_Product$item):void{
 $id=$order->get_id();$item_id=$item->get_id();$status='wc-'.$order->get_status('edit');$q=Probe::$db->prepare('INSERT INTO owned_wc_orders VALUES(?,?,?, ?,?, ?,?,?,?)');$q->execute([$id,$status,'GHS','10.00','0.00',7,'owned_local','Owned local','']);$q=Probe::$db->prepare('INSERT INTO owned_wc_order_operational_data VALUES(?,?, ?,?,NULL)');$q->execute([$id,$order->get_order_key('edit'),'0.00','0.00']);$q=Probe::$db->prepare("INSERT INTO owned_wc_order_addresses VALUES(?,'shipping','','','','','','')");$q->execute([$id]);$q=Probe::$db->prepare("INSERT INTO owned_posts VALUES(?,'shop_order',?)");$q->execute([$id,$status]);$q=Probe::$db->prepare('INSERT INTO owned_woocommerce_order_items VALUES(?,?,?,?)');$q->execute([$item_id,'Owned product','line_item',$id]);
 foreach(['_product_id'=>'33','_variation_id'=>'0','_qty'=>'1','_line_total'=>'10.00','_line_tax'=>'0.00','_line_subtotal'=>'10.00','_line_subtotal_tax'=>'0.00','_line_tax_data'=>serialize(['total'=>[],'subtotal'=>[]])]as$key=>$value){$q=Probe::$db->prepare('INSERT INTO owned_woocommerce_order_itemmeta(order_item_id,meta_key,meta_value)VALUES(?,?,?)');$q->execute([$item_id,$key,$value]);}
 foreach(['_order_key'=>$order->get_order_key('edit'),'_order_currency'=>'GHS','_order_total'=>'10.00','_order_tax'=>'0.00','_order_shipping'=>'0.00','_order_shipping_tax'=>'0.00','_customer_user'=>'7']as$key=>$value){$q=Probe::$db->prepare('INSERT INTO owned_postmeta(post_id,meta_key,meta_value)VALUES(?,?,?)');$q->execute([$id,$key,$value]);}
}
/** Same actual new objects survive native acknowledgement; no Stage DTO is manufactured. */
function writer_initial_attempt(CetechDeliveryEngine\Application\CustomerPolicy\CustomerPolicyPreparedCheckout$p,CetechDeliveryEngine\Application\CustomerPolicy\PolicyNativeOrderStager$stager,int$id=123,int$item_id=456,bool$clear=true,?callable$mint=null,string$key='native-key',bool$foreign=false):array{
 global$rawline,$rawpackage;
 if($clear){foreach(['wc_orders_meta','wc_order_addresses','wc_order_operational_data','wc_orders','posts','postmeta','woocommerce_order_itemmeta','woocommerce_order_items']as$table)Probe::$db->exec('DELETE FROM owned_'.$table);}
 $item=new WC_Order_Item_Product(0,0);$order=new WC_Order($item,0);Probe::$order=$order;$item->add_meta_data(CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_LINE_SNAPSHOT,$rawline);$item->add_meta_data(CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION,'2');$order->add_meta_data('is_vat_exempt','no');if($foreign)$item->add_meta_data('_cetech_de_quote_unknown','raw');$stager->bind_line($key,$item);
 if($p->route()==='store_api'){$order->native_status('checkout-draft');$order->native_acknowledge($id,$item_id);writer_native_rows($order,$item);$item->save();$order->save();}
 if($mint!==null){$issued=$mint($order,$item);if($issued instanceof CetechDeliveryEngine\Application\CustomerPolicy\CustomerPolicyPreparedCheckout)$p=$issued;}else$stager->begin_attempt($order,$p);
 if($p->route()==='classic'){$order->native_acknowledge($id,$item_id);writer_native_rows($order,$item);$item->save();$order->save();}
 // Model only the original legacy package handler, while the exact typed permission is live.
 if(!$stager->allows_initial_legacy_write($order,$p))throw new RuntimeException('Initial legacy permission unavailable.');
 $order->add_meta_data(CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT,$rawpackage);$order->add_meta_data(CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION,'2');$order->save();$order->native_status('pending');Probe::$db->exec("UPDATE owned_wc_orders SET status='wc-pending' WHERE id=".$id);Probe::$db->exec("UPDATE owned_posts SET post_status='wc-pending' WHERE ID=".$id);return[$order,$item];
}

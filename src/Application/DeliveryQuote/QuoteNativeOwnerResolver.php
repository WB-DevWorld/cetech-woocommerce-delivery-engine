<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
/** Read-only binding to an already initialized native Woo session. Does not set cookies. */
final class QuoteNativeOwnerResolver {
 public const KEY_EPOCH='native_quote_v1';
 public function current(): QuoteOwner {
  if(!function_exists('WC')||!function_exists('get_current_blog_id')||!function_exists('get_current_user_id')||!function_exists('wp_salt')) {throw new \RuntimeException('Native quote context unavailable.');}
  $wc=WC();$session=$wc->session??null;$customer=$wc->customer??null;
  if(!$session instanceof \WC_Session_Handler || !$customer instanceof \WC_Customer || !method_exists($session,'has_session') || !$session->has_session()) {throw new \RuntimeException('Native quote context unavailable.');}
  if(function_exists('has_filter')&&false!==has_filter('salt')){throw new \RuntimeException('Native quote context unavailable.');}$id=$session->get_customer_id();$site=get_current_blog_id();$user=get_current_user_id();$salt=wp_salt('auth');
  if(!is_string($id)||''===$id||strlen($id)>128||!is_int($site)||$site<1||!is_int($user)||$user<0||!is_string($salt)||strlen($salt)<16){throw new \RuntimeException('Native quote context unavailable.');}
  if($user>0 && (string)$user!==$id){throw new \RuntimeException('Native quote context unavailable.');}
  $token=$user>0 && function_exists('wp_get_session_token')?wp_get_session_token():'';
  if($user>0 && (!is_string($token)||''===$token)){throw new \RuntimeException('Native quote context unavailable.');}
  return QuoteOwner::from_array(['site_id'=>$site,'kind'=>$user>0?'customer':'guest','principal_hash'=>hash_hmac('sha256','native-quote-principal-v1:'.$site.':'.($user>0?'u'.$user:'g'.$id),$salt),'session_hash'=>hash_hmac('sha256','native-quote-session-v1:'.$site.':'.$id.':'.$token,$salt),'key_epoch'=>QuoteNativeContextIdentity::from_private_key($salt)->key_epoch()]);
 }
}

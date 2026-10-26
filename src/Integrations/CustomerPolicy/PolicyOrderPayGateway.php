<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Integrations\CustomerPolicy;

/** Native adapter guards the actual gateway invocation, not just the visible method list. */
final class PolicyOrderPayGateway extends \WC_Payment_Gateway {
 public function __construct(private readonly \WC_Payment_Gateway$gateway,private readonly PolicyCheckoutRuntime$runtime){foreach(get_object_vars($gateway)as$key=>$value){if(property_exists($this,$key))$this->$key=$value;}}
 public function process_payment($order_id){if(!is_int($order_id)&&(!is_string($order_id)||!ctype_digit($order_id)))throw new \RuntimeException('Delivery policies need review before payment.');if(!$this->runtime->verify_payment((int)$order_id,(string)$this->gateway->id,(string)$this->gateway->get_title()))throw new \RuntimeException('Delivery policies need review before payment.');$this->runtime->delegating((int)$order_id);return$this->gateway->process_payment($order_id);}
 public function validate_fields(){return$this->gateway->validate_fields();}
 public function payment_fields(){return$this->gateway->payment_fields();}
 public function is_available(){return$this->gateway->is_available();}
 public function supports($feature){return$this->gateway->supports($feature);}
 public function get_title(){return$this->gateway->get_title();}
 public function get_description(){return$this->gateway->get_description();}
 public function get_icon(){return$this->gateway->get_icon();}
}

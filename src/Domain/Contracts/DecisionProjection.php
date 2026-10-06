<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Contracts;

use InvalidArgumentException;
use Throwable;

/** Internal purpose-specific serializers. No pricing, persistence or logger adoption. */
final class DecisionProjection {

	public static function for_shopper(DecisionContext $context): array {
		$reasons = [];
		foreach ($context->reason_codes() as $code) {
			$category = DecisionReasonCode::public_category($code);
			$reasons[$category] = ['category' => $category, 'message_key' => DecisionReasonCode::message_key($code)];
		}
		return [
			'contract_version' => DecisionContext::CONTRACT_VERSION,
			'decision_kind' => match ($context->operation()) {
				'configuration.resolve' => 'configuration',
				'coverage.match' => 'coverage',
				'rate.quote' => 'quote',
				default => 'settings_change',
			},
			'status' => $context->outcome(),
			'reasons' => array_values($reasons),
			'recovery_action' => match ($context->outcome()) {
				'unconfirmed' => 'reconcile_original_request',
				'unavailable', 'rejected' => isset($reasons['destination_required']) ? 'reload_and_submit' : 'contact_support',
				default => null,
			},
			'correlation_id' => $context->request()->correlation_id,
			'complete' => $context->is_complete(),
		];
	}

	/**
	 * The application supplies current capability/object/parent authority, not a
	 * cached grant. Check before sensitive loading and again before disclosure.
	 * The target must have been resolved by the server, not by a caller's IDs alone.
	 */
	public static function for_admin(DecisionTarget $target, RequestContext $request, callable $authorize, callable $load): array|ContractError {
		$context = self::load_authorized($target, $request, $authorize, $load, 'admin_explanation');
		if ($context instanceof ContractError) { return $context; }
		return [
			'contract_version' => DecisionContext::CONTRACT_VERSION,
			'operation' => $context->operation(),
			'target' => self::target_fields($target),
			'evaluated_at' => $context->evaluated_at(),
			'request_id' => $request->request_id,
			'correlation_id' => $request->correlation_id,
			'outcome' => $context->outcome(),
			'reason_codes' => $context->reason_codes(),
			'configuration_versions' => $context->configuration_versions(),
			'provenance' => array_map(static fn(DecisionProvenance $field): array => $field->admin_fields(), $context->provenance()),
			'trace' => $context->trace()->entries(),
			'completeness' => self::completeness($context),
			'cache_state' => $context->cache_state(),
		];
	}

	/** Safe diagnostic facts only. Producing a log entry does not prove a mutation. */
	public static function for_diagnostic_log(DecisionContext $context, string $severity = 'info'): array {
		if (!in_array($severity, ['debug', 'info', 'warning', 'error'], true)) {
			throw new InvalidArgumentException('Invalid diagnostic severity.');
		}
		$versions = $context->configuration_versions();
		return [
			'contract_version' => DecisionContext::CONTRACT_VERSION,
			'severity' => $severity,
			'operation' => $context->operation(),
			'evaluated_at' => $context->evaluated_at(),
			'request_id' => $context->request()->request_id,
			'correlation_id' => $context->request()->correlation_id,
			'outcome' => $context->outcome(),
			'reason_codes' => $context->reason_codes(),
			'configuration_revisions' => null === $versions ? null : ['global' => $versions['global'], 'product' => $versions['product'], 'variation' => $versions['variation']],
			'completeness' => self::completeness($context),
			'cache_state' => $context->cache_state(),
		];
	}

	/** Projection only: the owned transaction/audit append is a later adopter. */
	public static function for_material_audit(DecisionTarget $target, RequestContext $request, callable $authorize, callable $load): array|ContractError {
		$context = self::load_authorized($target, $request, $authorize, $load, 'material_audit');
		if ($context instanceof ContractError) { return $context; }
		$facts = $context->material_change();
		if (null === $facts) {
			return new ContractError('invalid_input', $request, 'reload_and_submit', [], [['field' => 'semantic_payload', 'code' => 'required']]);
		}
		return [
			'contract_version' => DecisionContext::CONTRACT_VERSION,
			'operation' => $context->operation(),
			'operation_version' => $facts->identity->operation_version,
			'target' => self::target_fields($target),
			'actor_id' => $facts->actor_id,
			'reason_code' => $facts->reason_code,
			'request_id' => $request->request_id,
			'correlation_id' => $request->correlation_id,
			'before_revision' => $facts->before_revision,
			'after_revision' => $facts->after_revision,
			'changed_fields' => $facts->changed_fields(),
			'accepted_change' => true,
			'completion_outcome' => $facts->completion->state,
			'publication_pending' => $facts->completion->publication_pending,
			'private_completion_identity' => $facts->identity->namespace_digest(),
		];
	}

	private static function load_authorized(DecisionTarget $target, RequestContext $request, callable $authorize, callable $load, string $purpose): DecisionContext|ContractError {
		if (!self::authorized($authorize, $target, $purpose)) {
			return new ContractError('not_authorized', $request, 'contact_support');
		}
		try {
			$context = $load($target);
		} catch (Throwable) {
			return new ContractError('temporarily_unavailable', $request, 'contact_support');
		}
		if (!$context instanceof DecisionContext || !$context->target()->equals($target) || !self::authorized($authorize, $target, $purpose)) {
			return new ContractError('not_authorized', $request, 'contact_support');
		}
		return $context;
	}

	private static function authorized(callable $authorize, DecisionTarget $target, string $purpose): bool {
		try { return true === $authorize($target, $purpose); } catch (Throwable) { return false; }
	}

	private static function target_fields(DecisionTarget $target): array {
		return ['site_id' => $target->site_id, 'product_id' => $target->product_id, 'variation_id' => $target->variation_id, 'slice_key' => $target->slice_key];
	}

	private static function completeness(DecisionContext $context): array {
		$trace = $context->trace();
		return ['complete' => $context->is_complete(), 'source_complete' => $trace->source_complete(), 'truncated' => $trace->was_truncated(), 'captured_entries' => $trace->captured_count(), 'observed_entries' => $trace->observed_count()];
	}
}

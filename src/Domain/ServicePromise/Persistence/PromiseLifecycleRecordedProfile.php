<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise\Persistence;

use CetechDeliveryEngine\Application\ServicePromise\Persistence\PromiseLifecycleOperationProfile;
use CetechDeliveryEngine\Domain\Operation\OperationRecord;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;

/** Parse-only physical acknowledgment linkage. No current source reads or permission callbacks. */
final class PromiseLifecycleRecordedProfile {
	public static function assert_receipt( array $operation_row, array $event_row, PromiseSourceReceipt $receipt, array $target_row ): void {
		$r = $receipt->private_facts();
		$record = OperationRecord::from_row( $operation_row, PromiseLifecycleOperationProfile::receipt_profile( $r['operation'] ), $event_row );
		if ( 'accepted' !== $record->state || null === $record->completion || null === $record->event || $record->site_id !== $r['site_id'] || $record->namespace_hash !== $r['namespace_hash'] || $record->intent_hash !== $r['intent_hash'] || $record->target_hash !== $r['target_digest'] || $record->event->before_revision !== $r['before_revision'] || $record->event->after_revision !== $r['after_revision'] ) { self::invalid(); }
		$f = $record->completion->result;
		if ( $f['accepted_at'] !== RuleTime::parse( $r['accepted_at'] )->epoch_microseconds() || $f['author_user_id'] !== $r['author_user_id'] || $f['site_digest'] !== hash( 'sha256', $r['site_key'] ) || $record->event->actor['authority_hash'] !== $r['authority_hash'] || $record->event->actor['principal_hash'] !== $r['principal_hash'] || (int) $target_row['site_id'] !== $r['site_id'] || $target_row['site_key'] !== $r['site_key'] ) { self::invalid(); }
		if ( 'version' === $r['kind'] ) {
			$prefix = 'superseded' === $r['role'] ? 'predecessor_' : '';
			if ( $f['object_id'] !== $r['object_id'] || $f['logical_digest'] !== $r['logical_digest'] || $f[$prefix . 'version_id'] !== (int) $target_row['id'] || $f[$prefix . 'version_uuid'] !== $r['version_uuid'] || $f[$prefix . 'domain_version'] !== $r['domain_version'] || $f[$prefix . 'body_digest'] !== $r['content_digest'] || $f[$prefix . 'version_before_revision'] !== $r['version_before_revision'] || $f[$prefix . 'version_revision'] !== $r['version_after_revision'] || $f[$prefix . 'source_receipt_hash'] !== $receipt->digest() || (int) $target_row['object_id'] !== $r['object_id'] || $target_row['version_uuid'] !== $r['version_uuid'] || (int) $target_row['domain_version'] !== $r['domain_version'] || $target_row['body_digest'] !== $r['content_digest'] || $r['logical_digest'] !== PromiseStoredObject::identity_digest( $r['site_id'], $r['site_key'], $target_row['kind'], $target_row['logical_id'] ) ) { self::invalid(); }
			if ( 'superseded' === $r['role'] ) { if ( ! $f['has_predecessor'] || 'retired' !== $r['state'] ) { self::invalid(); } }
			elseif ( $f['state'] !== $r['state'] || $f['declared_from'] !== RuleTime::parse( $r['declared_from'] )->epoch_microseconds() || $f['has_until'] !== ( null !== $r['declared_until'] ) || $f['declared_until'] !== ( null === $r['declared_until'] ? 0 : RuleTime::parse( $r['declared_until'] )->epoch_microseconds() ) ) { self::invalid(); }
			if ( 'primary' === $r['role'] && ( $f['version_guards']['predecessor_version_id'] !== ( null === $target_row['predecessor_version_id'] ? 0 : (int) $target_row['predecessor_version_id'] ) || in_array( $r['operation'], [ 'promise.version.schedule', 'promise.version.activate', 'promise.version.publish', 'promise.version.retire' ], true ) && ( $f['version_guards']['schedule_expected_object_revision'] !== (int) ( $target_row['schedule_expected_object_revision'] ?? 0 ) || $f['version_guards']['schedule_expected_published_version_id'] !== (int) ( $target_row['schedule_expected_published_version_id'] ?? 0 ) ) ) ) { self::invalid(); }
			if ( $target_row['declared_from'] !== $r['declared_from'] || $target_row['declared_until'] !== $r['declared_until'] ) { self::invalid(); }
		} else {
			if ( $f['assignment_id'] !== (int) $target_row['id'] || $f['assignment_key_hash'] !== $r['assignment_key_hash'] || $r['assignment_key_hash'] !== self::assignment_key_hash( $target_row ) || $f['before_revision'] !== $r['before_revision'] || $f['revision'] !== $r['after_revision'] || $f['generation_before'] !== $r['generation_before'] || $f['generation'] !== $r['generation_after'] || $f['state'] !== $r['mode'] || $f['source_receipt_hash'] !== $receipt->digest() ) { self::invalid(); }
			$p = $r['policy_publication'];
			if ( null !== $p && ( $f['policy_version_id'] !== (int) $target_row['policy_version_id'] || $f['policy_object_id'] !== (int) $target_row['policy_object_id'] || $f['policy_content_digest'] !== $p['reference']['digest'] ) ) { self::invalid(); }
		}
	}
	public static function assert_object_head( array $operation_row, array $event_row, PromiseSourceReceipt $receipt, array $head_row, array $version_row ): void {
		self::assert_receipt( $operation_row, $event_row, $receipt, $version_row ); $r = $receipt->private_facts();
		$record = OperationRecord::from_row( $operation_row, PromiseLifecycleOperationProfile::receipt_profile( $r['operation'] ), $event_row ); $facts = $record->completion->result;
		if ( 'version' !== $r['kind'] || 'primary' !== $r['role'] || (int) $head_row['id'] !== $r['object_id'] || (int) $head_row['site_id'] !== $r['site_id'] || $head_row['site_key'] !== $r['site_key'] || $head_row['kind'] !== $version_row['kind'] || $head_row['logical_id'] !== $version_row['logical_id'] || $head_row['latest_source_receipt_digest'] !== $receipt->digest() || $head_row['updated_at'] !== $r['accepted_at'] ) { self::invalid(); }
		foreach ( [ 'revision', 'last_sequence', 'draft_version_id', 'scheduled_version_id', 'published_version_id', 'latest_version_id' ] as $field ) { if ( $facts['object_head'][$field] !== ( null === $head_row[$field] ? 0 : PromiseStorageCodec::integer( $head_row[$field], 0 ) ) ) { self::invalid(); } }
	}

	private static function assignment_key_hash( array $row ): string { $key = array_intersect_key( $row, array_flip( PromiseStoredAssignment::KEY_FIELDS ) ); foreach ( [ 'site_id', 'scope_id' ] as $field ) { $key[$field] = PromiseStorageCodec::integer( $key[$field], 'site_id' === $field ? 1 : 0 ); } return PromiseStoredAssignment::key_digest( $key ); }
	private static function invalid(): never { throw new \InvalidArgumentException( 'Promise source lacks its exact acknowledged original command.' ); }
}

<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DataLifecycle;

use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Domain\Operation\OperationJson;

/** Private, bounded maintenance checkpoint; never business acceptance/history. */
final readonly class DataLifecycleProgress implements \JsonSerializable {
	public int $format;
	public int $site_id;
	public string $class;
	public string $policy_digest;
	public string $run_id;
	public string $mode;
	public int $cutoff_utc;
	public int $ceiling_id;
	public int $cursor_id;
	public int $revision;
	public string $checkpoint_token;
	public ?string $last_batch_id;
	public int $last_batch_sequence;
	public string $status;
	public int $inspected;
	public int $deleted;
	public int $renewed;
	public int $protected;
	public int $disappeared;
	public int $invalid;
	public ?string $last_error_code;
	public string $run_manifest_hash;

	private const FIELDS = [ 'format', 'site_id', 'class', 'policy_digest', 'run_id', 'mode', 'cutoff_utc', 'ceiling_id', 'cursor_id', 'revision', 'checkpoint_token', 'last_batch_id', 'last_batch_sequence', 'status', 'inspected', 'deleted', 'renewed', 'protected', 'disappeared', 'invalid', 'last_error_code', 'run_manifest_hash' ];
	public const COUNT_FIELDS = [ 'inspected', 'deleted', 'renewed', 'protected', 'disappeared', 'invalid' ];

	private function __construct( array $data ) {
		if ( array_is_list( $data ) || [] !== array_diff( self::FIELDS, array_keys( $data ) ) || [] !== array_diff( array_keys( $data ), self::FIELDS ) ) { self::invalid(); }
		foreach ( [ 'format', 'site_id', 'cutoff_utc', 'ceiling_id', 'cursor_id', 'revision', 'last_batch_sequence', ...self::COUNT_FIELDS ] as $field ) {
			if ( ! is_int( $data[$field] ) || $data[$field] < 0 ) { self::invalid(); }
		}
		if ( 1 !== $data['format'] || $data['site_id'] < 1 || $data['cutoff_utc'] < 1 || $data['revision'] < 1 || $data['cursor_id'] > $data['ceiling_id'] || DataLifecycleManifest::CACHE_CLASS !== $data['class'] || ! in_array( $data['mode'], [ 'expired', 'uninstall_cache' ], true ) || ! in_array( $data['status'], [ 'running', 'paused_refused', 'outcome_unknown', 'completed' ], true ) ) { self::invalid(); }
		foreach ( [ 'policy_digest', 'checkpoint_token', 'run_manifest_hash' ] as $field ) { if ( ! is_string( $data[$field] ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/D', $data[$field] ) ) { self::invalid(); } }
		if ( ! is_string( $data['run_id'] ) || 1 !== preg_match( '/\A[0-9a-f]{32}\z/D', $data['run_id'] ) || ( null !== $data['last_batch_id'] && ( ! is_string( $data['last_batch_id'] ) || 1 !== preg_match( '/\A[0-9a-f]{32}\z/D', $data['last_batch_id'] ) ) ) || ( 0 === $data['last_batch_sequence'] ) !== ( null === $data['last_batch_id'] ) ) { self::invalid(); }
		if ( null !== $data['last_error_code'] && ! in_array( $data['last_error_code'], [ 'storage_refused', 'checkpoint_conflict', 'policy_changed', 'not_authorized', 'invalid_input' ], true ) ) { self::invalid(); }
		if ( ( 'completed' === $data['status'] ) !== ( $data['cursor_id'] === $data['ceiling_id'] ) || $data['deleted'] > $data['inspected'] || $data['renewed'] > $data['inspected'] || $data['protected'] > $data['inspected'] || $data['disappeared'] > $data['inspected'] || $data['invalid'] > $data['inspected'] || ! hash_equals( self::manifest_hash( $data ), $data['run_manifest_hash'] ) ) { self::invalid(); }
		foreach ( self::FIELDS as $field ) { $this->{$field} = $data[$field]; }
	}

	public static function initial( int $site, string $policy, string $mode, int $cutoff, int $ceiling ): self {
		$data = [ 'format' => 1, 'site_id' => $site, 'class' => DataLifecycleManifest::CACHE_CLASS, 'policy_digest' => $policy, 'run_id' => bin2hex( random_bytes( 16 ) ), 'mode' => $mode, 'cutoff_utc' => $cutoff, 'ceiling_id' => $ceiling, 'cursor_id' => 0, 'revision' => 1, 'checkpoint_token' => bin2hex( random_bytes( 32 ) ), 'last_batch_id' => null, 'last_batch_sequence' => 0, 'status' => 0 === $ceiling ? 'completed' : 'running', 'inspected' => 0, 'deleted' => 0, 'renewed' => 0, 'protected' => 0, 'disappeared' => 0, 'invalid' => 0, 'last_error_code' => null ];
		$data['run_manifest_hash'] = self::manifest_hash( $data );
		return new self( $data );
	}
	public static function from_array( array $data ): self { return new self( $data ); }
	public static function from_json( string $json ): self {
		if ( strlen( $json ) > 16384 ) { self::invalid(); }
		$data = get_object_vars( OperationJson::decode( $json ) );
		return new self( $data );
	}
	public function to_array(): array { $data = []; foreach ( self::FIELDS as $field ) { $data[$field] = $this->{$field}; } return $data; }
	public function to_json(): string { return json_encode( $this->to_array(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ); }
	public function counts(): array { $counts = []; foreach ( self::COUNT_FIELDS as $field ) { $counts[$field] = $this->{$field}; } return $counts; }
	public function safe(): array { return [ 'status' => $this->status, 'mode' => $this->mode, 'complete' => 'completed' === $this->status, 'counts' => $this->counts(), 'last_error_code' => $this->last_error_code ]; }
	public function same_checkpoint( self $other ): bool { return hash_equals( $this->to_json(), $other->to_json() ); }
	public function checkpoint( int $cursor, array $counts, ?string $error = null ): self {
		if ( $this->revision === PHP_INT_MAX || $this->last_batch_sequence === PHP_INT_MAX || $cursor < $this->cursor_id || [] !== array_diff( array_keys( $counts ), self::COUNT_FIELDS ) || [] !== array_diff( self::COUNT_FIELDS, array_keys( $counts ) ) ) { self::invalid(); }
		$data = $this->to_array(); $data['cursor_id'] = $cursor; $data['revision']++; $data['checkpoint_token'] = bin2hex( random_bytes( 32 ) ); $data['last_batch_id'] = bin2hex( random_bytes( 16 ) ); $data['last_batch_sequence']++; $data['status'] = $cursor === $this->ceiling_id ? 'completed' : 'running'; $data['last_error_code'] = $error;
		foreach ( $counts as $field => $count ) { if ( ! is_int( $count ) || $count < $this->{$field} ) { self::invalid(); } $data[$field] = $count; }
		return new self( $data );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'A lifecycle checkpoint requires an explicit projection.' ); }
	private static function manifest_hash( array $data ): string {
		$manifest = []; foreach ( [ 'format', 'site_id', 'class', 'policy_digest', 'run_id', 'mode', 'cutoff_utc', 'ceiling_id' ] as $field ) { $manifest[$field] = $data[$field]; }
		return hash( 'sha256', 'cetech-data-lifecycle-run-v1:' . json_encode( $manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
	}
	private static function invalid(): never { throw new \InvalidArgumentException( 'Lifecycle checkpoint is invalid.' ); }
}

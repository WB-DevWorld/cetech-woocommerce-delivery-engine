<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\PolicyAssignment;

use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Application\PolicyAssignment\{PolicyAssignmentService, PolicyCurrentCatalog};
use CetechDeliveryEngine\Application\ReturnPolicy\ReturnPolicyCatalogService;
use CetechDeliveryEngine\Application\RefundPolicy\RefundPolicyCatalogService;
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationCommitResult, OperationConnectionFactory, OperationPhaseObserver, OperationSession};
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentMap, PolicyAssignmentState, PolicyAssignmentCommand};
use CetechDeliveryEngine\Domain\ReturnPolicy\{ReturnPolicyFamily, ReturnPolicyContent};
use CetechDeliveryEngine\Domain\RefundPolicy\{RefundPolicyFamily, RefundPolicyContent};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent, RuleLifecycleCommand};
use CetechDeliveryEngine\Infrastructure\Persistence\{PolicyAssignmentStore, OperationStoreSchema, RuleLifecycleSchema};

/** Owned sequential PDO model; does not establish physical SQL locks/native WP. */
final class PolicyAssignmentFixture {
 public readonly PolicyAssignmentUnitFactory $factory;
 public readonly ReturnPolicyFamily $return_family;
 public readonly RefundPolicyFamily $refund_family;
 public readonly PolicyAssignmentStore $store;
 public mixed $allowed = true;
 public ?\Closure $on_authorize = null;
 public function __construct() {
  $scope = static fn(OperationIdentity $identity,array $scope):bool => 1 === $identity->site_id;
  $author = static fn(int $site,int $author,array $scope):bool => 1 === $site && 9 === $author;
  $binding = static fn(OperationIdentity $identity,int $author,array $scope):bool => 'staff:9' === $identity->principal && 9 === $author;
  $this->return_family = new ReturnPolicyFamily($scope,$author,$binding);
  $this->refund_family = new RefundPolicyFamily($scope,$author,$binding);
  $this->factory = new PolicyAssignmentUnitFactory();
  $this->store = new PolicyAssignmentStore(1,PolicyAssignmentUnitFactory::PREFIX,static fn(string $name,string $group):bool => true);
 }
 public static function uuid(int $id):string { return sprintf('00000000-0000-4000-8000-%012x',$id); }
 public function authorize(OperationIdentity $identity,?int $actor=null):mixed { if(null !== $this->on_authorize){($this->on_authorize)($identity,$actor);} return true === $this->allowed ? 1 === $identity->site_id && PolicyAssignmentCommand::AUTHORITY === $identity->authority && 'staff:9' === $identity->principal && (null === $actor || 9 === $actor) : $this->allowed; }
 public function catalog(OperationIdentity $identity):PolicyCurrentCatalog { return new PolicyCurrentCatalog($this->return_family,$this->refund_family,$identity); }
 public function service(OperationIdentity $identity,?OperationPhaseObserver $observer=null):PolicyAssignmentService { return new PolicyAssignmentService($this->factory,$this->store,$this->catalog($identity),[$this,'authorize'],new PolicyAssignmentUnitReadiness(),$observer); }
 public function identity(array $payload,string $token='original',string $operation=PolicyAssignmentCommand::OPERATION):OperationIdentity {
  $c=$payload['coordinate']; return new OperationIdentity(1,PolicyAssignmentCommand::AUTHORITY,'staff:9',$operation,1,PolicyAssignmentCommand::make_target($c['scope_type'],$c['scope_id'],$payload['offer_id']),$token);
 }
 public function state(?array $coordinate=null):PolicyAssignmentState {
  $c=$coordinate ?? PolicyAssignmentState::make_coordinate(1,'global',0); $name=$this->store->name($c);
  $row=$this->factory->inspection()->query('SELECT * FROM '.$this->factory->table('options').' WHERE option_name='.$this->factory->inspection()->quote($name))->fetch(\PDO::FETCH_ASSOC);
  return false === $row ? PolicyAssignmentState::absent($c['site_id'],$c['scope_type'],$c['scope_id'],$c['parent_id']) : PolicyAssignmentState::from_physical($c['site_id'],$c['scope_type'],$c['scope_id'],$c['parent_id'],(int)$row['option_id'],$row['option_value']);
 }
 public function payload(?array $coordinate=null,int $offer=0,array $instructions=[],array $changes=[]):array {
  $c=$coordinate ?? PolicyAssignmentState::make_coordinate(1,'global',0); $opened=$this->state($c);
  $instructions=array_replace(['return_policy_v1'=>['mode'=>'inherit'],'refund_policy_v1'=>['mode'=>'inherit']],$instructions);
  $reviewed=[];foreach($instructions as $family=>$slot){$reviewed[$family]='assigned' === $slot['mode'] ? $this->witness($family,$slot['policy_uuid']) : null;}
  return array_replace(['format'=>1,'coordinate'=>$c,'offer_id'=>$offer,'opened'=>['row_id'=>$opened->row_id(),'bytes'=>$opened->original_bytes()],'instructions'=>$instructions,'reviewed'=>$reviewed,'actor_user_id'=>9,'reason'=>'Owned policy assignment test','generation'=>$opened->generation() ?? self::uuid(900)],$changes);
 }
 public function attempt(array $payload,string $token='original',?OperationPhaseObserver $observer=null):OperationAttemptResult { $identity=$this->identity($payload,$token);return $this->service($identity,$observer)->attempt($identity,$payload,RequestContext::create()); }
 public function publish(string $family,int $logical=101,int $version=1,array $changes=[]):array {
  $return='return_policy_v1' === $family;$profile=$return ? $this->return_family : $this->refund_family;
  $fields=array_replace(['name'=>'Fixture '.$family,'customer_title'=>'Test policy','summary'=>"Test summary.\nSecond line.",'full_text'=>'Test-only policy terms.','exceptions'=>''],$changes);
  $content=$return ? ReturnPolicyContent::from_array($fields) : RefundPolicyContent::from_array($fields);
  $payload=['family'=>$family,'logical_uuid'=>self::uuid($logical),'version_uuid'=>self::uuid($version),'scope'=>['policy_uuid'=>self::uuid($logical)],'payload'=>$content->to_rule_payload(),'start_mode'=>'immediate','effective_from'=>null,'effective_until'=>null,'priority'=>0,'author_user_id'=>9,'change_reason'=>'Fixture publication','predecessor_uuid'=>null,'preconditions'=>['family_revision'=>0,'logical_id'=>0,'logical_revision'=>0,'version_id'=>0,'version_revision'=>0,'predecessor_id'=>0,'predecessor_revision'=>0]];
  $guard=$this->factory->inspection()->query('SELECT revision FROM '.$this->table('rule_family_guards').' WHERE family_code='.$this->factory->inspection()->quote($family))->fetchColumn();
  $payload['preconditions']['family_revision']=false === $guard ? 0 : (int)$guard;
  $service=$return ? new ReturnPolicyCatalogService($profile,$this->factory,static fn():bool=>true,new PolicyAssignmentUnitReadiness()) : new RefundPolicyCatalogService($profile,$this->factory,static fn():bool=>true,new PolicyAssignmentUnitReadiness());
  $make=fn(string $op,string $token):OperationIdentity=>new OperationIdentity(1,'wordpress','staff:9',$op,1,RuleLifecycleCommand::make_target_key($family,RuleContent::scope_hash($profile,$payload['scope']),$payload['logical_uuid']),$token);
  $create=$service->attempt($make('rule.draft.create','seed-'.$family.'-'.$logical),$payload,RequestContext::create());if('accepted'!==$create->outcome->state){throw new \RuntimeException('Owned policy create failed:'.($create->outcome->error?->code ?? $create->outcome->state));}
  $payload=$this->opened($family,$payload['logical_uuid'],$payload['version_uuid']);
  $publish=$service->attempt($make('rule.publish','publish-'.$family.'-'.$logical),$payload,RequestContext::create());if('accepted'!==$publish->outcome->state){throw new \RuntimeException('Owned policy publish failed:'.($publish->outcome->error?->code ?? $publish->outcome->state));}
  return $this->witness($family,$payload['logical_uuid']);
 }
 public function opened(string $family,string $logical_uuid,string $version_uuid):array {
  $db=$this->factory->inspection();$logical=$db->query('SELECT * FROM '.$this->table('logical_rules').' WHERE logical_uuid='.$db->quote($logical_uuid))->fetch(\PDO::FETCH_ASSOC);$version=$db->query('SELECT * FROM '.$this->table('rule_versions').' WHERE version_uuid='.$db->quote($version_uuid))->fetch(\PDO::FETCH_ASSOC);$guard=$db->query('SELECT * FROM '.$this->table('rule_family_guards').' WHERE id='.(int)$logical['family_guard_id'])->fetch(\PDO::FETCH_ASSOC);$prior=null === $version['supersedes_version_id'] ? null : $db->query('SELECT * FROM '.$this->table('rule_versions').' WHERE id='.(int)$version['supersedes_version_id'])->fetch(\PDO::FETCH_ASSOC);
  return ['family'=>$family,'logical_uuid'=>$logical_uuid,'version_uuid'=>$version_uuid,'scope'=>json_decode($logical['scope_json'],true,8,JSON_THROW_ON_ERROR),'payload'=>json_decode($version['payload_json'],true,8,JSON_THROW_ON_ERROR),'start_mode'=>$version['start_mode'],'effective_from'=>$version['effective_from'],'effective_until'=>$version['effective_until'],'priority'=>(int)$version['priority'],'author_user_id'=>(int)$version['author_user_id'],'change_reason'=>$version['change_reason'],'predecessor_uuid'=>$prior['version_uuid'] ?? null,'preconditions'=>['family_revision'=>(int)$guard['revision'],'logical_id'=>(int)$logical['id'],'logical_revision'=>(int)$logical['revision'],'version_id'=>(int)$version['id'],'version_revision'=>(int)$version['row_revision'],'predecessor_id'=>(int)($prior['id'] ?? 0),'predecessor_revision'=>(int)($prior['row_revision'] ?? 0)]];
 }
 public function witness(string $family,string $logical_uuid):array {
  $db=$this->factory->inspection();$l=$db->query('SELECT * FROM '.$this->table('logical_rules').' WHERE logical_uuid='.$db->quote($logical_uuid))->fetch(\PDO::FETCH_ASSOC);$v=$db->query('SELECT * FROM '.$this->table('rule_versions').' WHERE id='.(int)$l['current_published_version_id'])->fetch(\PDO::FETCH_ASSOC);$g=$db->query('SELECT * FROM '.$this->table('rule_family_guards').' WHERE id='.(int)$l['family_guard_id'])->fetch(\PDO::FETCH_ASSOC);
  if($g['family_code']!==$family){throw new \RuntimeException('Wrong fixture policy family.');}
  return ['policy_uuid'=>$logical_uuid,'version_uuid'=>$v['version_uuid'],'logical_revision'=>(int)$l['revision'],'version_revision'=>(int)$v['row_revision'],'content_hash'=>$v['content_hash'],'guard_revision'=>(int)$g['revision']];
 }
 public function table(string $suffix):string{return $this->factory->table($suffix);}
 public function rows(string $suffix):array{return $this->factory->inspection()->query('SELECT * FROM '.$this->table($suffix).' ORDER BY '.('options'===$suffix?'option_id':'id'))->fetchAll(\PDO::FETCH_ASSOC);}
 public function count(string $suffix):int{return count($this->rows($suffix));}
 public function close():void{$this->factory->close();}
}

final class PolicyAssignmentUnitReadiness implements OperationReadiness { public function assert_ready( OperationSession $session ): void {} }

/** Sequential in-memory transport adapter; domain and operation behavior remain production code. */
final class PolicyAssignmentUnitFactory implements OperationConnectionFactory {
	public const PREFIX = 'policy_assignment_unit_';
	private ?\PDO $database;
	public string $clock = '2026-10-24 10:00:00.000001';
	public int $opens = 0;
	public int $commits = 0;
	public int $rollbacks = 0;
	public int $retirements = 0;
	public bool $reject_audit = false;
	public bool $reject_completion = false;
	public ?int $lost_ack_at = null;
	public ?int $not_sent_at = null;
	public ?\Closure $on_open = null;
	public ?\Closure $on_query = null;
	public ?\Closure $on_commit = null;
	public ?\Closure $filter_rows = null;
	public bool $throw_retired_metadata = false;
	public bool $throw_transaction_metadata = false;
	public ?int $unconfirmed_retirement = null;
	public array $sessions = [];
	public array $sql = [];

	public function __construct() {
		$options = [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ];
		$this->database = method_exists( \PDO::class, 'connect' ) ? \PDO::connect( 'sqlite::memory:', null, null, $options ) : new \PDO( 'sqlite::memory:', null, null, $options );
		$clock = fn (): string => $this->clock;
		if ( method_exists( $this->database, 'createFunction' ) ) { $this->database->createFunction( 'UTC_NOW', $clock ); }
		else { $this->database->sqliteCreateFunction( 'UTC_NOW', $clock ); }
		foreach ( [ OperationStoreSchema::class, RuleLifecycleSchema::class ] as $schema ) {
			foreach ( $schema::SUFFIXES as $suffix ) {
				$columns = [];
				foreach ( $schema::columns( $suffix ) as $name => $facts ) {
					if ( 'id' === $name ) { $columns[] = 'id INTEGER PRIMARY KEY AUTOINCREMENT'; continue; }
					$column = $name . ( str_contains( $facts[0], 'int' ) ? ' INTEGER' : ' TEXT' ) . ( $facts[1] ? '' : ' NOT NULL' );
					if ( null !== $facts[2] ) { $column .= ' DEFAULT ' . $this->database->quote( $facts[2] ); } $columns[] = $column;
				}
				$table = $this->table( $suffix ); $this->database->exec( 'CREATE TABLE ' . $table . ' (' . implode( ',', $columns ) . ')' );
				foreach ( $schema::indexes( $suffix ) as $name => $facts ) {
					if ( 'PRIMARY' !== $name ) { $this->database->exec( 'CREATE ' . ( $facts['unique'] ? 'UNIQUE ' : '' ) . 'INDEX ' . $table . '_' . $name . ' ON ' . $table . '(' . implode( ',', $facts['columns'] ) . ')' ); }
				}
			}
		}
		$this->database->exec( 'CREATE TABLE ' . self::PREFIX . 'options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT COLLATE NOCASE NOT NULL UNIQUE, option_value TEXT NOT NULL, autoload TEXT NOT NULL)' );
	}
	public function inspection(): \PDO { return $this->database ?? throw new \RuntimeException( 'Owned return-policy unit model is closed.' ); }
	public function table( string $suffix ): string {
		if ( 'options' === $suffix ) { return self::PREFIX . 'options'; }
		if ( ! in_array( $suffix, array_merge( OperationStoreSchema::SUFFIXES, RuleLifecycleSchema::SUFFIXES ), true ) ) { throw new \InvalidArgumentException( 'Unsupported unit-model table.' ); }
		return self::PREFIX . 'delivery_engine_' . $suffix;
	}
	public function open(): OperationSession {
		++$this->opens; if ( null !== $this->on_open ) { ( $this->on_open )( $this ); }
		$session = new PolicyAssignmentUnitSession( $this ); $this->sessions[] = $session; return $session;
	}
	public function close(): void { foreach ( $this->sessions as $session ) { $session->retire(); } $this->database = null; }
}

final class PolicyAssignmentUnitSession implements OperationSession {
	private ?\PDO $db;
	private bool $retired = false;
	private bool $retirement_confirmed = true;
	private int $error = 0;
	private array $tables = [];
	public function __construct( private readonly PolicyAssignmentUnitFactory $factory ) { $this->db = $factory->inspection(); }
	public function site_id(): int { return 1; }
	public function table_prefix(): string { return PolicyAssignmentUnitFactory::PREFIX; }
	public function charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function begin(): bool { $this->tables = []; return ! $this->retired && ! $this->in_transaction() && $this->db->beginTransaction(); }
	public function commit(): OperationCommitResult {
		++$this->factory->commits;
		if ( $this->factory->not_sent_at === $this->factory->commits ) { $this->factory->not_sent_at = null; return OperationCommitResult::NotSent; }
		$this->db->commit(); if ( null !== $this->factory->on_commit ) { ( $this->factory->on_commit )( $this->factory ); }
		if ( $this->factory->lost_ack_at === $this->factory->commits ) { $this->factory->lost_ack_at = null; $this->retire(); return OperationCommitResult::Unconfirmed; }
		return OperationCommitResult::Acknowledged;
	}
	public function rollback(): bool { ++$this->factory->rollbacks; return ! $this->retired && $this->db->inTransaction() && $this->db->rollBack(); }
	public function retire(): bool {
		if ( $this->retired ) { return $this->retirement_confirmed; }
		if ( null !== $this->db && $this->db->inTransaction() ) { $this->db->rollBack(); }
		$this->db = null; ++$this->factory->retirements; $this->retired = true; $this->retirement_confirmed = $this->factory->unconfirmed_retirement !== $this->factory->retirements; return $this->retirement_confirmed;
	}
	public function is_retired(): bool { if ( $this->factory->throw_retired_metadata ) { throw new \RuntimeException('Owned metadata fault'); } return $this->retired; }
	public function in_transaction(): bool { if ( $this->factory->throw_transaction_metadata ) { throw new \RuntimeException('Owned metadata fault'); } return ! $this->retired && null !== $this->db && $this->db->inTransaction(); }
	public function validate_tables( array $tables ): bool {
		if ( ! $this->in_transaction() ) { return false; }
		foreach ( $tables as $table ) {
			if ( ! str_starts_with( $table, PolicyAssignmentUnitFactory::PREFIX ) || false === $this->db->query( 'SELECT 1 FROM ' . $table . ' LIMIT 0' ) ) { return false; }
			$this->tables[$table] = true;
		}
		return true;
	}
	public function query( string $sql ): int|false {
		if ( ! $this->in_transaction() ) { return false; } $this->error = 0; $this->record( $sql );
		if ( $this->factory->reject_audit && str_starts_with( $sql, 'INSERT INTO `' . $this->factory->table( 'operation_changes' ) . '`' ) ) { return false; }
		if ( $this->factory->reject_completion && str_starts_with( $sql, 'UPDATE `' . $this->factory->table( 'operation_records' ) . '`' ) && 1 === preg_match( '/completion_json\s*=/', $sql ) ) { return false; }
		if ( 1 === preg_match( '/^(?:INSERT INTO|UPDATE) `([^`]+)`/', $sql, $match ) && ! isset( $this->tables[$match[1]] ) ) { return false; }
		try { return $this->db->exec( $this->sql( $sql ) ); }
		catch ( \PDOException $e ) { $this->error = str_contains( $e->getMessage(), 'UNIQUE constraint' ) ? 1062 : 1; return false; }
	}
	public function get_row( string $sql ): array|null|false { $rows = $this->get_results( $sql ); return false === $rows ? false : ( $rows[0] ?? null ); }
	public function get_results( string $sql ): array|false {
		if ( ! $this->in_transaction() ) { return false; } $this->record( $sql );
		if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) ) {
			return $this->filtered( $sql, array_map( static fn( string $name, string $type ): array => [ 'Field' => $name, 'Type' => $type, 'Null' => 'NO', 'Extra' => 'option_id' === $name ? 'auto_increment' : '' ], [ 'option_id','option_name','option_value','autoload' ], [ 'bigint unsigned','varchar(191)','longtext','varchar(20)' ] ) );
		}
		if ( str_starts_with( $sql, 'SHOW INDEX' ) ) { return $this->filtered( $sql, [ [ 'Key_name'=>'PRIMARY','Non_unique'=>'0','Seq_in_index'=>'1','Sub_part'=>null,'Index_type'=>'BTREE','Expression'=>null,'Column_name'=>'option_id' ], [ 'Key_name'=>'option_name','Non_unique'=>'0','Seq_in_index'=>'1','Sub_part'=>null,'Index_type'=>'BTREE','Expression'=>null,'Column_name'=>'option_name' ] ] ); }
		try { return $this->filtered( $sql, $this->db->query( $this->sql( $sql ) )->fetchAll( \PDO::FETCH_ASSOC ) ); }
		catch ( \PDOException ) { $this->error = 1; return false; }
	}
	public function prepare( string $sql, mixed ...$args ): string {
		$index = 0; return preg_replace_callback( '/%([ds])/', function ( array $m ) use ( $args, &$index ): string { $value = $args[$index++]; return 'd' === $m[1] ? (string) $value : $this->db->quote( $value ); }, $sql );
	}
	public function errno(): int { return $this->error; }
	public function insert_id(): int { return (int) $this->db->lastInsertId(); }
	private function filtered( string $sql, array $rows ): array|false { return null === $this->factory->filter_rows ? $rows : ( $this->factory->filter_rows )( $sql, $rows, $this->factory ); }
	private function record( string $sql ): void { $this->factory->sql[] = $sql; if ( null !== $this->factory->on_query ) { ( $this->factory->on_query )( $sql, $this->factory ); } }
	private function sql( string $sql ): string {
		$sql = preg_replace( '/ FORCE INDEX \(\`?option_name\`?\)/', '', $sql );
		$sql = preg_replace( '/\bBINARY ([a-z_]+)/', '$1 COLLATE BINARY', $sql );
		return str_replace( [ ' FOR UPDATE', 'UTC_TIMESTAMP(6)', 'OCTET_LENGTH(option_value)' ], [ '', 'UTC_NOW()', 'LENGTH(CAST(option_value AS BLOB))' ], $sql );
	}
}

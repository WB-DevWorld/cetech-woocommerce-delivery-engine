# R23 — Independent policy assignments and effective admin preview — 2026-10-24

Owner renews continuation and seven to ten parallel streams and states they perform all work. Root/Codex and six finite agents own this bounded cross-workstream source/owned disposable CI task; permanent OWNERSHIP is unchanged. Qualified R22 base34602c3276eff2b2f3f67689bb2d8e333f507f30/tree5a2e21bb66f45606cb6d6a20858edbcddff1267f, PR136, run40136637987 all8jobs and all26original audits PASS; nativeReturn16+Refund16, SQL536/all28guards. Earlier pending-at-freeze records are historical.

## Frozen product scope and decisions

Both independent families: return_policy_v1 and refund_policy_v1. Assign whole logical policy references at global→product→variation scope, each with default slot and up to32 real delivery_offer_id slots. Within one scope use selectedoffer's non-inherit instruction first, then that scope default; ECR alone applies increasingly specific non-inherit instructions. A more specific assignment can override an ancestor disable. An assigned but unavailable policy yields unresolved, never silent fallback. All-inherit yields not_assigned; explicit disable yields disabled. Offer0 is the default EDIT slot only; effective preview requires an existing positive real offer, independent of availability slices and delivery/store_pickup enums.

Explicit implementation decision, supported by accepted source01 current-vs-historical examples: FOLLOW CURRENT published logical policy for future captures. This is an inference frozen here, not a claim of an earlier owner decision. Native save also retains the reviewed current version witness and refuses a changed reviewed publication before effect. Later publication can advance a followed logical policy; each fresh preview captures again. No historical/customer writer is added. Preview is saved-state as-of SQL evaluated_at, not hypothetical editor values or enduring checkout admission.

Use a separate AUTHORITATIVE managed native option per site/scope. Do not touch configuration_scopes/fields/collections, nine delivery fields, Promise assignments or existing policy catalog production classes. Root adds one pure ECR entrypoint; old resolve/memo/fingerprint remain unchanged. Two preservation-only lifecycle prefixes add declarations146→148; no schema migration, new business table, flag, cap, retention or cleanup authority. Source census955→968 (13classes), dev1.0.0-dev.policy-assignments.1. Schema12/41current/39retained stores/23flags/372classifications unchanged.

Source and exact marked owned disposable remote CI only; owned in-memory SQLite unit doubles permitted. No local WordPress/MariaDB, SSH/site/protected checkpoints/live settings/adoption/payment, merge/release/deploy. Cursor alone has SSH.

## Domain API — four new classes, Domain/PolicyAssignment

PolicyAssignmentMap:
- constants FAMILIES=[return_policy_v1,refund_policy_v1], MAX_BYTES4096,MAX_OFFERS32.
- from_array(int site,string family,array data):self; from_json(int site,string family,string json):self; empty(int site,string family):self.
- exact canonical envelope format=1,site_id,family,definition_hash,default,offers (offers object, canonical positive decimal keys numerically sorted, no leadingzero/overflow).
- slots exactly {mode:inherit|disabled} or {mode:assigned,policy_uuid:canonicalUUID}; definition_hash is matching qualified family policy_hash. Fixed profile shape is trusted; hash is not authority.
- facts():array,json():string,digest():string,family():string,site_id():int, with_instruction(int offer,array slot):self, instruction(int real_offer):array{instruction,slot}; offer0 patchesdefault. instruction returns slot0for default or positive selectedofferID; it selects same-scope offer then default; no scope inheritance here. Namespaced family/site/profile/body digest. Genericserialization refused/redacted. Validate complete escaped outer budgets too.

PolicyAssignmentState:
- make_coordinate(int site,string scope_type,int scope_id,int parent_id=0):array exact site_id/scope_type/scope_id/parent_id; global0/parent0, product>0/parent0, variation>0/parent>0 and distinct IDs.
- absent(int site,string type,int id,int parent=0):self; from_physical(int site,string type,int id,int parent,int row_id,string bytes):self.
- getters coordinate():array,row_id():int,revision():int,generation():?string,original_bytes():string,digest():string,map(string family):PolicyAssignmentMap.
- stored canonical exact format=1,site_id,scope_type,scope_id,parent_id,generation(UUID),revision>=1,return_map/refund_map canonical JSON strings,actor_user_id>0,reason(string<=1000),changed_at SQL UTC microsecond string. Absence row0/rev0/generationnull/bytesempty and both empty maps.
- record_json(array coordinate,string generation,int revision,PolicyAssignmentMap return,PolicyAssignmentMap refund,int actor,string reason,string changed_at):string; validate complete16KiB envelope and physical frame. Do not include row_id in stored body.

PolicyAssignmentCommand implements OperationCommand:
- OPERATION=policy.assignment.set, VERSION1, AUTHORITY=policy_assignment.configuration.
- from_array(OperationIdentity,array):self; identity readonly; opened():PolicyAssignmentState; coordinate():array; offer_id():int; instructions():array familykeys; reviewed():array; actor_user_id():int; reason():string; generation():string.
- payload exact format1,coordinate,offer_id,opened={row_id,bytes},instructions={return_policy_v1:slot,refund_policy_v1:slot},reviewed={return_policy_v1:null|witness,refund_policy_v1:null|witness},actor_user_id,reason,generation. New generation server UUID for absence, existinggeneration otherwise; retained in original recovery.
- witness exact policy_uuid,version_uuid,logical_revision,version_revision,content_hash,guard_revision; mandatory for any assigned instruction (even unchanged), null otherwise; matches selected slotlogical. No foreign family/version/site.
- make_target(string type,int id,int offer):string canonical policy-assignment:type:id:offer:offer. Identity operation/version/site/target must match; principal opaque, authority/actor binding comes only from captured grant, no parser in core.
- implement intent():CanonicalIntent exactly OperationCommand (other schema methods belong to the operation profile); complete original intent includes all original fields and bounded original bytes, no reconstruction from current rows. payload():array explicit recovery export.
- desired_bytes(string SQLtime):string is a pure canonical proposal beforeINSERT; desired(string SQLtime,int actual_row_id):PolicyAssignmentState reconstructs actual event/replay facts. They patch exactly BOTH selected slots using original fullmaps, preserves every other slot/family; no_change():bool for exact two map bytes equality.

PolicyEffectiveSelection:
- private detached value with family,disposition assigned|disabled|not_assigned|unresolved, optional typed ReturnPolicyVersion|RefundPolicyVersion, privateprovenance,materialfingerprint,evaluated_at,closedcode.
- factories assigned(string family,RuleSnapshot,string policy_uuid,RuleTime,array provenance,string assignment_fingerprint):self; disabled/not_assigned/unresolved corresponding family/time/provenance/fingerprint/code.
- getters family(),disposition(),version(),provenance(),fingerprint(),evaluated_at(),code(). Assigned must prove complete nonhypothetical current published candidate via evaluator + correct trusted family and convert typedversion. Include authored contentdigest AND C01hash/source revisions/profile; distinguish these hashes. No generic persistence/debug disclosure. Timestamp separate from stable material fingerprint.

## Core storage/writer — three new classes

Infrastructure/Persistence/PolicyAssignmentStore constructor(int site,string prefix,?callable cache_delete=null):
PREFIX=cetech_de_policy_assignment_v1_. name(array coordinate):string includes canonical site/type/id (parent remains body witness; reparent mismatch refuses). options_table(session), assert_ready(session), guard(session,bool create), current(session,array coordinate):State, write(session,State before,string bytes):State, invalidate(array coordinate):bool.
Native route/site/prefix/default-wpdb/no-db.php is captured OUTSIDE owned SQL by native authority; store pins capturedsite/prefix and verifies optionsSQL structure/owner purely. Reuse DataLifecycleOptionsStore.assert_ready ONLY; its geo writeallowlist is forbidden. Dedicated C01 guard codepolicy_assignment_v1 with fixed namespaced definitionhash. Create only in write profile; current read locks ifexists withoutcreate. Familyguard lockorder dedicated→refund→return, then assignment scopes global→product→variation, then chosen logical/version. Existing catalogwriters lock own family first; no reverse option→family order.
Own bounded uncached SELECT FORCEINDEX option_name FORUPDATE; canonical name/collated alias/byte length/autoload/fullunique native index validation; exact option_id/revision/original bytes CAS; INSERTautoload off, UPDATE preservesautoload; fresh readback. Sessionsite/prefix/physicalowner fences. No deletion API/cache authority. First-use creation may failclosed/recover rather than promise stale; deterministic raceproof first preparesguard/state.

Application/PolicyAssignment/PolicyAssignmentOperationProfile implements OperationProfile:
constructor(callable pure_authorize,PolicyAssignmentStore,PolicyCurrentCatalog). Grant is (OperationIdentity,?int actor=null,?array coordinate=null,?int offer=null):bool. Preparseidentity stages maypassnull; parsedwrites bindactor+exactcanonical4fieldcoordinate+offer, andreadspassnullactor+exactcoordinate+offer BEFOREfactoryopen andafterretirement. CapturedNativegrant strictlycomparesnonnullcontext to originalnativefacts. validate_command rejects forgedactor through capturedgrant before SQL/reservation; principal staysopaque. The first6b753 candidate omittedparent in this capturedgrant (browserhandlerstillvalidated); follow-up1corrects3productionfiles and preservesoriginalpayload/target/version.
Own exactly existing options+C01 tables under same C03owner. After guard/catalog family locks, exact opened row/rev/generation/bytes CAS. Validate every supplied assigned reviewedhead under sameowner and SQLtime before effect; no data-driven family profiles. Nochange no write/audit. Changed state monotonicrev+1, originalgeneration, actor/reason/SQLtime; one material event reason_code policy_assignment_saved with before/after row/rev/bytesdigests and reviewedwitnesshash, one authenticated completion/publication. Generic event revisions equal physical revisions+1; firstphysical0-to-1 is auditevent1-to-2, and targetretainsactualrevisions. All actor/site/identity/intent/result/event/publication facts authenticated on replay. Recovery/history validation never re-resolves advanced policy heads or mutates maps. Late source/time check before CAS. Publish invalidates exactoption/alloptions/notoptions after retired owner only; no stale body republish. Scope option+bothmaps one atomicchange.

Application/PolicyAssignment/PolicyAssignmentService:
constructor(OperationConnectionFactory,PolicyAssignmentStore,PolicyCurrentCatalog,callable pure_authorize,?OperationReadiness=null,?OperationPhaseObserver=null).
attempt(OperationIdentity,mixed payload,RequestContext):OperationAttemptResult; reconcile(same). Standard C03 explicit profile registry only. Verify all owned sessions retired before exposure; unknown staysunknown, no cached ack. Native layer separately rechecks actual live grant after return.

## Current capture — two new application classes

PolicyCurrentCatalog constructor(ReturnPolicyFamily,RefundPolicyFamily,OperationIdentity).
lock_families(OperationSession):void fixed refund thenreturn, no create; snapshot(session,string family,string logicalUUID):RuleSnapshot (only selectedlogical + bounded<=1000 history/immediatepredecessor, refuse incomplete); assert_reviewed(session,array reviewed,RuleTime):void; assert_reviewed_at(OperationSession,array reviewed,RuleTime):void pure last-held-snapshot/time check with cheap nativeowner field fences; selection(session,string family,array instruction,array provenance,string assignment_fingerprint,RuleTime):PolicyEffectiveSelection.
All familycallbacks pure captured native grants. Correct family/profile/site/currentpointer, all four states, effectivewindow/sourcehash/author metadata validated. Never family-wide scan for effective preview. Typed detachedversions alone are not current proof.

PolicyAssignmentCurrentService constructor(factory,store,catalog,EffectiveConfigurationResolver,callable pure_authorize,?OperationReadiness=null).
read(OperationIdentity,array coordinate,int offer=0):PolicyAssignmentState; capture(OperationIdentity,array coordinate,int positive_real_offer):array{states:array,selections:array}.
Readoperation policy.assignment.read/version1, exacttarget/site/offer. Storeguard(false), catalogfamilies, assignmentchain global/product/variation appropriatefor target, then selectedlogical locks; SQL UTC afterlocks; ECR selects each wholeinstruction; catalogproduces currentdetachedselection. No sourcecurrent memo. Assignment changed_at and selected C01 guard.updated_at must not exceed fresh evaluated SQLtime; writes preserve monotonic changed_at even for nochange. Refusals are closed unavailable, never inherited terms.
Open→pinowner/site/prefix→readiness→begin→sameowner structure/locks→capture→ack rollback→ack retirement→final pure grant/owner binding, isolated best-effort cleanup even throwing metadata. Native late actualgrant before output. Throw closed RuntimeException on unreadable state; native notices hide internals.

## Root central ECR API

EffectiveConfigurationResolver::resolve_policy_instructions(array coordinate,int real_offer,array states):array with exact familykeys, each {instruction:slot,provenance:array}, plus shared assignment_fingerprint? Freeze top shape {instructions:array{family:slot},provenance:array{family:array},fingerprint:string}.
Validate exactstatechain coordinates/site/parent, root→product→variation, no missing/extra/reordered/hypothetical contexts. Same-scope offer first then default; increasinglyspecific noninherit replaces, otherfamily unchanged. Raw arrays are private captured proposals, not customer/current authority. Fingerprint binds all physicalstate IDs/revs/generations/digests, coordinate+realoffer+bothfamilydefinitions. Existing resolve/resolveAll/memo/deliveryfingerprints unchanged.

## Native authoring — four new classes

Integrations/PolicyAssignment/NativePolicyAssignmentAuthority:
can_access(),actor(),binding():array{site_id,prefix} (native defaultwpdb/no db.php captured outsideSQL),target(string type,int id):coordinate (actualWCproduct and server-derived variationparent, native edit_post product/parent),offer(int id):validatedactual ID (0defaultedit allowed; positiveexists viaexisting DeliveryOfferRepositoryInterface),identity_data(string operation,array coordinate,int offer,string token):7tuple,identity(array tuple):OperationIdentity,capture(identity,coordinate,offer):array{authorize:Closure,return_family:ReturnPolicyFamily,refund_family:RefundPolicyFamily},authorize(same):bool.
Constructor optional actual ProductTargetResolver/DeliveryOfferRepository ports asneeded; production wiredroot. Existing manage_delivery_settings AND manage_site_wide_defaults +AdminPageAccess/vendor restrictions for ALLscopes; no new/delegatedstaffauthority. Native current actor/site/productparent/offer/route read before transaction and afterretirement, retained callbackspure. Conservative originalrecovery requires same live target; deleted/reparented target remainsblocked, no takeoverhistorygrant invented.

Infrastructure/Persistence/WpdbPolicyAssignmentAdminIntent: qualified R22 byte transport as new strict namespace cetech_de_policy_assignment_intent_v1_, ownlock; same APIs/fences/max131072; no original class changes.

Application/PolicyAssignment/PolicyAssignmentAdminWorkflow constructor(authority,journal,OperationConnectionFactory,EffectiveConfigurationResolver,?readiness,?observer):
can_access(),view():array{review,blocked,preview,offers} (offers is a bounded native id/label picker projection),open(string type,int id,int offer=0):review,submit(string ticket,array fields,RequestContext):OperationAttemptResult,recover(retry|reconcile,RequestContext):OperationAttemptResult,preview(string ticket,RequestContext):array selections.
review serverholds coordinate+offer+opened fullstate+choice metadata for bothfamilies, opaque ticket, normal labels, originals. Save fields exact return_mode,return_policy_uuid,refund_mode,refund_policy_uuid,reason. Modesinherit|disabled|assigned; UUID only assigned. Build exactreviewed witness from serverchoice. Catalogselectorchoices use existing typedcatalogs under actual manager grants, preserve definition and at most64 complete publishedchoices perfamily; displaylabels atmost200UTF8bytes and exact6fieldwitnesses; overcaprefuses editor ratherthantruncatecatalog; no customer terms invented.
Original journal full7identity primitives+completeoriginalcommand/token/generation/actor/reason/CAS; ready→inflight acknowledgement before dispatch; refresh puregrants AFTERjournalhooks; nativeafterC03 retirement beforejournalclear/delayeddisclosure. Uncertain blocksfreshwrites and neverrebuildsfromadvancedstate. Reconcile historyonly originalrequest. Same R21 last-delete ack limitation/no postdeletecallback.
Preview uses ticket's servercoordinate andPOSITIVErealoffer, fresh SAVEDstate capture only; neverpostedproposedfields/default0. Fresh actualgrant aroundcapture/render; current observation is not checkoutauthority.

Presentation/Admin/PolicyAssignmentPage SLUGcetech-de-policy-assignments, ACTION_PREFIXcetech_de_policy_assignment_; closed POST+freshnative nonce viaAdminActionHandler, verbsopen/save/preview/retry/reconcile. Business labels, separate Return/Refund mode+policyselect, existingcurrentpublished names, reason, explicit latestpublished semantics. Defaultslot0 labeledDefaultpolicy. Savedpreview with positiveoffer only; show disposition and escapedtypedcustomerwording, no editableauthority/JSON/CAS/technicalversion. Extra fields/wrongnonce/method refused0effect; fresh grant at delayed render. Rootwires4newnativeclasses+menu only.

## Leases and qualification

Root: records/thisfreeze, ECRnewmethod+tests, centralcomposition/lifecycle/version/package/census/CI/publication/finaltwofreshness.
r23_domain: four domainclasses+newdomainunitfixtures/tests.
r23_storage_scope: store/profile/service+newunitintegrationfixture/tests; shared fixture APIs coordinated with current/security.
r23_effective: currentcatalog/currentservice+newtests.
r23_native: four nativeclasses+newnativeunitfixtures/tests.
r23_security_sql: NEW adversarialtests+realSQL13/support/worker; independentproductionreview.
r23_qualification: NEW native20HTTPhelper/wrapper/closedvalidator/protocoltests; independentnative review. No agentscommit/publish/editcentralfiles.

Retain all8jobs/536SQL/all28fullguards/all26oldartifacts and oldcase inventories. Add actual SQLminimum13 (or explicitlyenumeratedsubstantiveadditionalcases), native20 separateartifact→27. Rootnormalproductioncensus updates N17 producer/verifier and retained R22 exactcensus+protocolfixture only (old16meaningunchanged, currentpin changes honestly). No thresholds/suites/validators weakened. Local focusednew/affected once; fullretainedsuites onlyCI. Implementation, localchecks, exactpackage, all8CI/original27audits andsource/siteacceptance remain separate.

## Qualification transport correction and early failure order

Run 40145745701/head289ff6b2 remains failed and uncertified. The native nonce refusal and unchanged physical inspection passed, but the empty scope map was serialized as a PHP list. The inspect JSON boundary now explicitly emits an object for scope states, including zero rows. PHP internal arrays, real store/map codecs, all20 native assertions and the strict verifier remain unchanged. An actual helper/guarded-writer regression covers both zero rows and a populated global row with empty and positive numeric-keyed offer maps; only owned in-memory native/physical-read stubs are used, without WordPress or a SQL service. The old helper fails its empty subcase; the corrected23 protocol tests pass. This regression is transport evidence, not native acceptance.

Only the R23 wrapper call moves to the start of the HTTP branch, after the original495 receipt and restored CPT20 state. It depends on no later receipt or export, and its private fixture/listener cleanup completes before N18. All retained lanes, separate receipts, guards and final gates stay required in their prior relative order. A new exact-head complete run and all27 original audits are required; no successful job or receipt from either superseded candidate is substituted.

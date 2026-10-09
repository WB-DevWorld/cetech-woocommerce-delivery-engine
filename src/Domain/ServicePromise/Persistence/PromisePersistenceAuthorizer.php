<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise\Persistence;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;

/** Unmounted trusted grant boundary. Methods read only request-local grants already captured by the trusted host.
 * No SQL, stored source, hook or network reads; profiles consume a pure captured snapshot. */
interface PromisePersistenceAuthorizer {
	/** Identity and supplied scope are not grants. The host owns current permission truth.
	 * For promise.versions.read, global/0 specifically requests private arbitrary exact-reference
	 * read authority for this bound site, not a global mutation or product-only grant. Reads use
	 * author_user_id=0; every loaded policy scope is reauthorized after owner retirement.
	 */
	public function authorize( OperationIdentity $identity, PromiseSiteBinding $binding, array $scope, int $author_user_id ): bool;
	/** Recheck the original publishing author's currently admitted grant at explicit activation. */
	public function authorize_author( PromiseSiteBinding $binding, int $author_user_id, array $scope ): bool;
}

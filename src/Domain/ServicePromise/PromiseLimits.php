<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise;

/** Internal promise limits; retained quote/rule formats keep their own budgets. */
final class PromiseLimits {
	public const RECORD_BYTES = 32768;
	public const PACKET_BYTES = 65536;
	public const GRAPH_NODES = 16;
	public const GRAPH_EDGES = 32;
	public const CALENDARS = 16;
	public const INTERVALS_PER_DATE = 8;
	public const DATED_EXCEPTIONS = 366;
	public const LOOKAHEAD_DAYS = 730;
	public const CART_STEPS = 100000;
	public const VERSION_MAX = 1000000;
}

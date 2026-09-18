<?php

declare(strict_types=1);

/**
 * Isolated-lab Ghana Delivery Area fixture for issue #23 physical QA.
 *
 * Not a production seeder. Creates one Delivery Area with ≥20 Greater Accra
 * localities sharing one Rate Card. Does not deploy to FLAIROC/training/production.
 *
 * @return list<string>
 */
function cetech_de_ghana_greater_accra_locality_names(): array {
	return [
		'Accra',
		'Tema',
		'Madina',
		'Adenta',
		'Teshie',
		'Nungua',
		'Ashaiman',
		'Dodowa',
		'Amasaman',
		'Dansoman',
		'Kaneshie',
		'Osu',
		'Labadi',
		'Spintex',
		'East Legon',
		'Achimota',
		'Dome',
		'Haatso',
		'Legon',
		'Weija',
	];
}

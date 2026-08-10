<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

/**
 * Prepared field row for scoped configuration editors.
 */
final class FieldEditViewModel {

	/**
	 * @param list<string>             $allowed_modes
	 * @param array<string, string>    $mode_labels
	 * @param array<int|string, string>|null $selector_options
	 * @param list<int|string>         $configured_members
	 * @param list<int|string>         $inherited_members
	 * @param list<int|string>         $effective_members
	 * @param list<string>             $provenance_lines
	 * @param list<string>             $validation_messages
	 */
	public function __construct(
		public readonly string $field_key,
		public readonly string $label,
		public readonly string $description,
		public readonly bool $is_collection,
		public readonly string $value_type,
		public readonly array $allowed_modes,
		public readonly array $mode_labels,
		public readonly string $current_mode,
		public readonly mixed $configured_value,
		public readonly mixed $inherited_value,
		public readonly mixed $effective_value,
		public readonly string $effective_state,
		public readonly string $effective_state_label,
		public readonly string $effective_state_tone,
		public readonly string $provenance_label,
		public readonly array $provenance_lines,
		public readonly array $validation_messages,
		public readonly ?string $entity_kind,
		public readonly ?array $selector_options,
		public readonly ?array $enum_options,
		public readonly array $configured_members = [],
		public readonly array $inherited_members = [],
		public readonly array $effective_members = [],
		public readonly bool $is_global_root = false,
		public readonly string $configured_state_label = ''
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'field_key'              => $this->field_key,
			'label'                  => $this->label,
			'description'            => $this->description,
			'is_collection'          => $this->is_collection,
			'value_type'             => $this->value_type,
			'allowed_modes'          => $this->allowed_modes,
			'mode_labels'            => $this->mode_labels,
			'current_mode'           => $this->current_mode,
			'configured_value'       => $this->configured_value,
			'inherited_value'        => $this->inherited_value,
			'effective_value'        => $this->effective_value,
			'effective_state'        => $this->effective_state,
			'effective_state_label'  => $this->effective_state_label,
			'effective_state_tone'   => $this->effective_state_tone,
			'provenance_label'       => $this->provenance_label,
			'provenance_lines'       => $this->provenance_lines,
			'validation_messages'    => $this->validation_messages,
			'entity_kind'            => $this->entity_kind,
			'selector_options'       => $this->selector_options,
			'enum_options'           => $this->enum_options,
			'configured_members'     => $this->configured_members,
			'inherited_members'      => $this->inherited_members,
			'effective_members'      => $this->effective_members,
			'is_global_root'         => $this->is_global_root,
			'configured_state_label' => $this->configured_state_label,
		];
	}
}

<?php

declare(strict_types=1);

namespace Typdf\Forms;

/**
 * Represents one AcroForm field (a leaf node in the field tree).
 */
class FormField
{
    /**
     * @param string        $name             Full dotted field name (e.g. "address.street").
     * @param FieldType     $type             The logical field type.
     * @param string|bool|null $value         Current value: string for text/select/radio,
     *                                        bool for checkbox, null if unset.
     * @param string[]      $options          For select/radio: available export values.
     * @param bool          $isReadOnly       Whether the field has the ReadOnly flag set.
     * @param bool          $isRequired       Whether the field has the Required flag set.
     * @param int           $objectNumber     The PDF indirect-object number of the field dict.
     * @param int[]         $widgetObjectNumbers
     *                                        For radio groups: object numbers of all child
     *                                        widget annotations. For other types: empty.
     * @param string[]      $onStates         For buttons: the non-/Off appearance-state name(s)
     *                                        per widget (same order as $widgetObjectNumbers, or
     *                                        single entry for a standalone checkbox).
     */
    public function __construct(
        public readonly string $name,
        public readonly FieldType $type,
        public readonly string|bool|null $value,
        public readonly array $options,
        public readonly bool $isReadOnly,
        public readonly bool $isRequired,
        public readonly int $objectNumber,
        public readonly array $widgetObjectNumbers,
        public readonly array $onStates,
    ) {
    }
}

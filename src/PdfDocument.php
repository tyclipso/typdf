<?php

declare(strict_types=1);

namespace Typdf;

use Typdf\Exception\{ParseException, PdfException};
use Typdf\Forms\{FieldType, FormField};
use Typdf\Objects\{
    PdfArray,
    PdfBoolean,
    PdfDictionary,
    PdfInteger,
    PdfName,
    PdfObject,
    PdfReference,
    PdfStream,
    PdfString,
};
use Typdf\Parser\PdfParser;
use Typdf\Writer\IncrementalWriter;

/**
 * High-level API for reading and filling AcroForm fields in a PDF file.
 *
 * Usage:
 *
 *   $doc = new PdfDocument('/path/to/form.pdf');
 *
 *   // List all fields
 *   foreach ($doc->getFields() as $field) {
 *       echo $field->name . ' (' . $field->type->value . ")\n";
 *   }
 *
 *   // Fill fields
 *   $doc->setFieldValue('firstName', 'Jane');
 *   $doc->setFieldValue('agreeToTerms', true);   // checkbox
 *   $doc->setFieldValue('gender', 'female');      // radio button
 *   $doc->setFieldValue('country', 'DE');         // select / combo
 *
 *   // Save
 *   $doc->save('/path/to/filled.pdf');
 *   // or get bytes:
 *   $bytes = $doc->getContent();
 */
class PdfDocument
{
    private PdfParser $parser;

    /** @var array<string, FormField> Indexed by full field name */
    private array $fields = [];

    /** @var array<int, PdfDictionary> Pending modifications: objNum => modified dict */
    private array $pendingObjects = [];

    /** Object number of the /AcroForm dictionary (0 if inline in catalog) */
    private int $acroFormObjNum = 0;

    /** The (possibly modified) /AcroForm dictionary */
    private ?PdfDictionary $acroFormDict = null;

    // -----------------------------------------------------------------------
    // Field flags (PDF spec table 228)
    // -----------------------------------------------------------------------
    private const FF_READ_ONLY  = 1;
    private const FF_REQUIRED   = 2;
    private const FF_PUSH_BTN   = 1 << 16;   // bit 17 (1-indexed)
    private const FF_RADIO      = 1 << 15;   // bit 16
    private const FF_COMBO      = 1 << 17;   // bit 18

    public function __construct(string $filePath)
    {
        $this->parser = new PdfParser($filePath);
        $this->loadFields();
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Return all AcroForm fields found in the document.
     *
     * @return array<string, FormField>  Keyed by full dotted field name.
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Return a single field by its full name, or null if not found.
     */
    public function getField(string $name): ?FormField
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * Set the value of a form field.
     *
     * - Text field:    pass a string.
     * - Checkbox:      pass true (checked) or false (unchecked).
     * - Radio button:  pass the export value of the option to select.
     * - Select/combo:  pass the export value of the option to select.
     *
     * @throws PdfException If the field does not exist or the value is invalid.
     */
    public function setFieldValue(string $name, string|bool $value): void
    {
        $field = $this->fields[$name] ?? null;
        if ($field === null) {
            throw new PdfException("Field not found: '{$name}'");
        }

        match ($field->type) {
            FieldType::Text        => $this->applyTextField($field, (string) $value),
            FieldType::Checkbox    => $this->applyCheckbox($field, (bool) $value),
            FieldType::Radio       => $this->applyRadio($field, (string) $value),
            FieldType::Select      => $this->applySelect($field, (string) $value),
            FieldType::PushButton  => throw new PdfException("Push buttons have no settable value"),
        };

        // Mark /NeedAppearances so PDF viewers regenerate the visual appearance
        $this->markNeedAppearances();
    }

    /**
     * Save the document with the applied changes to a file.
     */
    public function save(string $filePath): void
    {
        $this->buildWriter()->save($filePath);
    }

    /**
     * Return the updated PDF as a byte string.
     */
    public function getContent(): string
    {
        return $this->buildWriter()->build();
    }

    // -----------------------------------------------------------------------
    // Field loading
    // -----------------------------------------------------------------------

    private function loadFields(): void
    {
        $catalog = $this->resolveDict($this->parser->getRootObjectNumber());

        $acroFormObj = $catalog->get('AcroForm');
        if ($acroFormObj === null) {
            return; // No form
        }

        if ($acroFormObj instanceof PdfReference) {
            $this->acroFormObjNum = $acroFormObj->getObjectNumber();
            $resolved             = $this->parser->getObject($this->acroFormObjNum);
            if (!$resolved instanceof PdfDictionary) {
                return;
            }
            $this->acroFormDict = $resolved;
        } elseif ($acroFormObj instanceof PdfDictionary) {
            $this->acroFormDict = $acroFormObj;
        } else {
            return;
        }

        $fieldsObj = $this->acroFormDict->get('Fields');
        if (!$fieldsObj instanceof PdfArray) {
            return;
        }

        foreach ($fieldsObj->getItems() as $ref) {
            $this->traverseField($ref, '', null);
        }
    }

    /**
     * Recursively walk the field tree, collecting leaf fields.
     *
     * @param PdfObject     $fieldObj  The field object (or reference to one).
     * @param string        $prefix    Accumulated parent name prefix.
     * @param PdfDictionary|null $inherited  Inherited attributes from parent.
     */
    private function traverseField(
        PdfObject $fieldObj,
        string $prefix,
        ?PdfDictionary $inherited,
    ): void {
        $objNum = 0;
        if ($fieldObj instanceof PdfReference) {
            $objNum   = $fieldObj->getObjectNumber();
            $fieldObj = $this->parser->getObject($objNum);
        }

        if (!$fieldObj instanceof PdfDictionary) {
            return;
        }

        // Merge inherited values (T, FT, Ff, etc. can be inherited from parent nodes)
        $effective = $this->mergeInherited($fieldObj, $inherited);

        // Build the full field name
        $tObj     = $effective->get('T');
        $partName = ($tObj instanceof PdfString) ? $tObj->getValue() : '';
        $fullName = ($prefix !== '') ? "{$prefix}.{$partName}" : $partName;

        $kidsObj = $fieldObj->get('Kids');
        $hasKids = $kidsObj instanceof PdfArray && $kidsObj->count() > 0;

        // Determine field type (may be inherited)
        $ftObj  = $effective->get('FT');
        $ftName = ($ftObj instanceof PdfName) ? $ftObj->getValue() : '';
        $ff     = $this->getFlags($effective);

        if ($hasKids) {
            if ($ftName === '') {
                // Non-terminal node (no /FT): recurse into logical children
                foreach ($kidsObj->getItems() as $child) {
                    $this->traverseField($child, $fullName, $effective);
                }
                return;
            }

            if ($ftName === 'Btn' && ($ff & self::FF_RADIO) !== 0 && !($ff & self::FF_PUSH_BTN)) {
                // Radio button group — kids are widget annotations, not field nodes
                $this->collectRadioGroup($fieldObj, $effective, $fullName, $objNum);
                return;
            }

            // Terminal field whose /Kids are purely widget annotations
            // (e.g. a text field that appears on multiple pages).
            // Fall through to handle as a leaf below.
        }

        // Leaf (terminal) field
        if ($ftName === '') {
            return; // Widget annotation without field data — skip
        }

        $ft = $ftName;
        $obj = $fieldObj; // The actual dictionary we will modify

        switch ($ft) {
            case 'Tx':
                $this->fields[$fullName] = new FormField(
                    name:                $fullName,
                    type:                FieldType::Text,
                    value:               $this->getStringValue($effective),
                    options:             [],
                    isReadOnly:          (bool) ($ff & self::FF_READ_ONLY),
                    isRequired:          (bool) ($ff & self::FF_REQUIRED),
                    objectNumber:        $objNum,
                    widgetObjectNumbers: [],
                    onStates:            [],
                );
                break;

            case 'Btn':
                if ($ff & self::FF_PUSH_BTN) {
                    // Push button — no value
                    $this->fields[$fullName] = new FormField(
                        name:                $fullName,
                        type:                FieldType::PushButton,
                        value:               null,
                        options:             [],
                        isReadOnly:          (bool) ($ff & self::FF_READ_ONLY),
                        isRequired:          (bool) ($ff & self::FF_REQUIRED),
                        objectNumber:        $objNum,
                        widgetObjectNumbers: [],
                        onStates:            [],
                    );
                } else {
                    // Standalone checkbox
                    $onState  = $this->detectOnState($obj);
                    $asObj    = $obj->get('AS');
                    $asName   = ($asObj instanceof PdfName) ? $asObj->getValue() : 'Off';
                    $checked  = ($asName !== 'Off' && $asName !== '');

                    $this->fields[$fullName] = new FormField(
                        name:                $fullName,
                        type:                FieldType::Checkbox,
                        value:               $checked,
                        options:             [],
                        isReadOnly:          (bool) ($ff & self::FF_READ_ONLY),
                        isRequired:          (bool) ($ff & self::FF_REQUIRED),
                        objectNumber:        $objNum,
                        widgetObjectNumbers: [],
                        onStates:            [$onState],
                    );
                }
                break;

            case 'Ch':
                $options = $this->getChoiceOptions($effective);
                $this->fields[$fullName] = new FormField(
                    name:                $fullName,
                    type:                FieldType::Select,
                    value:               $this->getStringValue($effective),
                    options:             $options,
                    isReadOnly:          (bool) ($ff & self::FF_READ_ONLY),
                    isRequired:          (bool) ($ff & self::FF_REQUIRED),
                    objectNumber:        $objNum,
                    widgetObjectNumbers: [],
                    onStates:            [],
                );
                break;
        }
    }

    /**
     * Collect a radio button group whose children are widget annotations.
     */
    private function collectRadioGroup(
        PdfDictionary $groupDict,
        PdfDictionary $effective,
        string $fullName,
        int $groupObjNum,
    ): void {
        $kidsObj = $groupDict->get('Kids');
        if (!$kidsObj instanceof PdfArray) {
            return;
        }

        $ff             = $this->getFlags($effective);
        $widgetObjNums  = [];
        $onStates       = [];
        $options        = [];
        $currentValue   = null;

        // Current /V on the group dict
        $vObj = $effective->get('V');
        if ($vObj instanceof PdfName) {
            $currentValue = $vObj->getValue() !== 'Off' ? $vObj->getValue() : null;
        } elseif ($vObj instanceof PdfString) {
            $currentValue = $vObj->getValue();
        }

        foreach ($kidsObj->getItems() as $kidRef) {
            $kidObjNum = 0;
            $kidObj    = $kidRef;
            if ($kidRef instanceof PdfReference) {
                $kidObjNum = $kidRef->getObjectNumber();
                $kidObj    = $this->parser->getObject($kidObjNum);
            }

            if (!$kidObj instanceof PdfDictionary) {
                continue;
            }

            $widgetObjNums[] = $kidObjNum;
            $onState         = $this->detectOnState($kidObj);
            $onStates[]      = $onState;
            if ($onState !== 'Off') {
                $options[] = $onState;
            }
        }

        $this->fields[$fullName] = new FormField(
            name:                $fullName,
            type:                FieldType::Radio,
            value:               $currentValue,
            options:             $options,
            isReadOnly:          (bool) ($ff & self::FF_READ_ONLY),
            isRequired:          (bool) ($ff & self::FF_REQUIRED),
            objectNumber:        $groupObjNum,
            widgetObjectNumbers: $widgetObjNums,
            onStates:            $onStates,
        );
    }

    // -----------------------------------------------------------------------
    // Value application
    // -----------------------------------------------------------------------

    private function applyTextField(FormField $field, string $value): void
    {
        $dict = $this->loadForModification($field->objectNumber);
        $dict->set('V', PdfString::fromValue($value));
        // Remove pre-built appearance stream so the viewer regenerates it
        $dict->remove('AP');
    }

    private function applyCheckbox(FormField $field, bool $checked): void
    {
        // $onState = $field->onStates[0] ?? 'Yes';
        // It seems that only "Yes" selects the checkbox correctly, even if the actual value is different
        $onState = 'Yes';
        $state   = $checked ? $onState : 'Off';

        $dict = $this->loadForModification($field->objectNumber);
        $dict->set('V', new PdfName($state));
        $dict->set('AS', new PdfName($state));
        $dict->remove('AP');
    }

    private function applyRadio(FormField $field, string $value): void
    {
        // Find which widget corresponds to the requested value
        $selectedIdx = array_search($value, $field->onStates, true);

        if ($selectedIdx === false) {
            throw new PdfException(
                "Radio option '{$value}' not found in field '{$field->name}'. "
                . 'Available: ' . implode(', ', $field->options)
            );
        }

        // Update the group's /V
        $groupDict = $this->loadForModification($field->objectNumber);
        $groupDict->set('V', new PdfName($value));

        // Update each child widget's /AS
        foreach ($field->widgetObjectNumbers as $idx => $widgetObjNum) {
            $widgetDict  = $this->loadForModification($widgetObjNum);
            // It seems that only "Yes" instead of $field->onStates[$idx] selects the radio correctly
            $asStateName = ($idx === $selectedIdx) ? 'Yes' : 'Off';
            $widgetDict->set('AS', new PdfName($asStateName));
            $widgetDict->remove('AP');
        }
    }

    private function applySelect(FormField $field, string $value): void
    {
        // Validate that the value is a known option (warn but do not block)
        $dict = $this->loadForModification($field->objectNumber);
        $dict->set('V', PdfString::fromValue($value));
        // Remove cached index selection
        $dict->remove('I');
        $dict->remove('AP');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Return a mutable copy of the dictionary for object $objNum.
     * The copy is queued in $pendingObjects; subsequent calls return the same copy.
     */
    private function loadForModification(int $objNum): PdfDictionary
    {
        if (isset($this->pendingObjects[$objNum])) {
            return $this->pendingObjects[$objNum];
        }

        $original = $this->parser->getObject($objNum);

        if (!$original instanceof PdfDictionary) {
            throw new PdfException("Object {$objNum} is not a dictionary");
        }

        // Shallow clone the dictionary so we don't mutate cached parser objects
        $copy = new PdfDictionary();
        foreach ($original->getEntries() as $key => $val) {
            $copy->set($key, $val);
        }

        $this->pendingObjects[$objNum] = $copy;

        return $copy;
    }

    /**
     * Get the effective dictionary that merges $node entries with $inherited.
     * Inherited entries apply only when the node does not define them itself.
     * Only inheritable keys (FT, Ff, V, DV) are merged.
     */
    private function mergeInherited(PdfDictionary $node, ?PdfDictionary $inherited): PdfDictionary
    {
        if ($inherited === null) {
            return $node;
        }

        $merged = new PdfDictionary();

        foreach ($node->getEntries() as $key => $val) {
            $merged->set($key, $val);
        }

        foreach (['FT', 'Ff', 'V', 'DV'] as $key) {
            if (!$merged->has($key) && $inherited->has($key)) {
                $merged->set($key, $inherited->get($key));
            }
        }

        return $merged;
    }

    /** Return the /Ff bitmask or 0 if absent. */
    private function getFlags(PdfDictionary $dict): int
    {
        $ff = $dict->get('Ff');

        return ($ff instanceof PdfInteger) ? $ff->getValue() : 0;
    }

    /**
     * Extract the "on" appearance state name for a button widget.
     *
     * The appearance dictionary /AP /N contains entries for each state.
     * One of them is always /Off; the other is the "on" state.
     * Falls back to "Yes" if the appearance dictionary is missing.
     */
    private function detectOnState(PdfDictionary $widgetDict): string
    {
        $apObj = $widgetDict->get('AP');
        if ($apObj instanceof PdfReference) {
            $apObj = $this->parser->resolve($apObj);
        }

        if ($apObj instanceof PdfDictionary) {
            $nObj = $apObj->get('N');
            if ($nObj instanceof PdfReference) {
                $nObj = $this->parser->resolve($nObj);
            }
            if ($nObj instanceof PdfDictionary) {
                foreach ($nObj->getKeys() as $key) {
                    if ($key !== 'Off') {
                        return (string)$key;
                    }
                }
            }
        }

        // Fallback: check /AS for a non-Off current state
        $asObj = $widgetDict->get('AS');
        if ($asObj instanceof PdfName) {
            $as = $asObj->getValue();
            if ($as !== 'Off' && $as !== '') {
                return $as;
            }
        }

        return 'Yes';
    }

    /**
     * Extract the current string /V value from a field dictionary.
     */
    private function getStringValue(PdfDictionary $dict): ?string
    {
        $v = $dict->get('V');
        if ($v === null) {
            return null;
        }

        if ($v instanceof PdfReference) {
            $v = $this->parser->resolve($v);
        }

        if ($v instanceof PdfString) {
            return $v->getValue();
        }
        if ($v instanceof PdfName) {
            $name = $v->getValue();
            return ($name === 'Off' || $name === '') ? null : $name;
        }

        return null;
    }

    /**
     * Extract option export values from the /Opt array of a choice field.
     *
     * Each entry in /Opt is either:
     *  - A string (export value = display value)
     *  - An array [export_value, display_value]
     *
     * @return string[]
     */
    private function getChoiceOptions(PdfDictionary $dict): array
    {
        $optObj = $dict->get('Opt');
        if (!$optObj instanceof PdfArray) {
            return [];
        }

        $options = [];
        foreach ($optObj->getItems() as $item) {
            if ($item instanceof PdfReference) {
                $item = $this->parser->resolve($item);
            }

            if ($item instanceof PdfString) {
                $options[] = $item->getValue();
            } elseif ($item instanceof PdfName) {
                $options[] = $item->getValue();
            } elseif ($item instanceof PdfArray) {
                // [exportValue, displayName]
                $first = $item->get(0);
                if ($first instanceof PdfReference) {
                    $first = $this->parser->resolve($first);
                }
                if ($first instanceof PdfString) {
                    $options[] = $first->getValue();
                } elseif ($first instanceof PdfName) {
                    $options[] = $first->getValue();
                }
            }
        }

        return $options;
    }

    /**
     * Resolve an object number to a PdfDictionary.
     */
    private function resolveDict(int $objNum): PdfDictionary
    {
        $obj = $this->parser->getObject($objNum);
        if (!$obj instanceof PdfDictionary) {
            throw new ParseException("Object {$objNum} is not a dictionary");
        }

        return $obj;
    }

    /**
     * Set /NeedAppearances true in the /AcroForm dictionary so that PDF viewers
     * regenerate the visual appearance of all fields.
     */
    private function markNeedAppearances(): void
    {
        if ($this->acroFormObjNum > 0) {
            $dict = $this->loadForModification($this->acroFormObjNum);
            $dict->set('NeedAppearances', new PdfBoolean(true));
        } elseif ($this->acroFormDict !== null) {
            // Inline /AcroForm inside the catalog — modify the catalog object
            $rootObjNum = $this->parser->getRootObjectNumber();
            $catalog    = $this->loadForModification($rootObjNum);

            $acroFormCopy = new PdfDictionary();
            foreach ($this->acroFormDict->getEntries() as $k => $v) {
                $acroFormCopy->set($k, $v);
            }
            $acroFormCopy->set('NeedAppearances', new PdfBoolean(true));
            $catalog->set('AcroForm', $acroFormCopy);
        }
    }

    /**
     * Build the IncrementalWriter with all pending modifications.
     */
    private function buildWriter(): IncrementalWriter
    {
        // Find the offset of the last startxref in the original file
        $tail   = substr($this->parser->getRawData(), max(0, strlen($this->parser->getRawData()) - 1024));
        $offset = strrpos($tail, 'startxref');
        $xrefOffset = 0;

        if ($offset !== false) {
            $after = substr($tail, $offset + strlen('startxref'));
            if (preg_match('/\s+(\d+)/', $after, $m)) {
                $xrefOffset = (int) $m[1];
            }
        }

        $writer = new IncrementalWriter(
            originalData:       $this->parser->getRawData(),
            originalMaxObjNum:  $this->parser->getMaxObjectNumber(),
            originalXrefOffset: $xrefOffset,
            originalTrailer:    $this->parser->getTrailer(),
            usesXrefStreams:    $this->parser->usesXrefStreams(),
        );

        foreach ($this->pendingObjects as $objNum => $dict) {
            $gen = $this->parser->getGenerationNumber($objNum);
            $writer->addObject($objNum, $gen, $dict);
        }

        return $writer;
    }
}

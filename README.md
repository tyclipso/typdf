# typdf

typdf is a PHP library for filling forms in PDF documents.

**Capabilities**

- Supports setting the field values for
  - text fields
  - radio buttons
  - checkboxes
  - selects
- Can show all avaible fields in the document
- Can save the PDF as file or return the binary content
- Supports PDFs with Flate-encoded streams

**Limits**

- Encrypted PDFs are not supported
- typdf is only intended for form filling, not for other PDF operations

## Installation

The easiest way to use this library is via composer.

`composer require tyclipso/typdf`

## Usage

```
$doc = new \Typdf\PdfDocument('/path/to/form.pdf');

// Inspect fields
foreach ($doc->getFields() as $name => $field) {
  echo $field->name.' ('.$field->type->value.'): '.($field->value ?? 'empty')."\n";
}

// Fill fields
$doc->setFieldValue('firstName', 'Jane');    // text
$doc->setFieldValue('agreeToTerms', true);   // checkbox
$doc->setFieldValue('gender', 'female');     // radio button
$doc->setFieldValue('country', 'DE');        // select/combo

// Save
$doc->save('/path/to/filled.pdf');
// or: $bytes = $doc->getContent();
```

## Contact

- Website: https://www.tyclipso.net 
- Github: https://github.com/tyclipso/typdf
